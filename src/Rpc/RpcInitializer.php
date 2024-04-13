<?php
namespace VictorOpusculo\PComp\Rpc;

final class RpcInitializer
{
    public function __construct(array $routesTree, string $routeQueryParam = "route", string $callQueryParam = "call", string $fetchUrlsTemplate = "/functions.php?route={route}&call={call}")
	{
		$urlPath = strtolower($_GET[$routeQueryParam] ?? '');

		if ($urlPath && $urlPath[0] == '/')
			$urlPath = substr($urlPath, 1);

		if (!$urlPath)
			$urlPath = '/';

		if ($urlPath && $urlPath[strlen($urlPath) - 1] !== '/')
			$urlPath .= '/';

		$paths = explode('/', $urlPath);

		$routeClass = null;

		$currentNamespace = $routesTree;
		$finalRoutePaths = [];
		$matches = [];

        /** @var ?string */
        $lastFunctionClassFound = null;

		$routesStatus = array_fill(0, count($paths), null);
		foreach ($paths as $pIndex => $path)
		{
            if (array_key_exists('__functions', $currentNamespace))
                $lastFunctionClassFound = $currentNamespace['__functions'];

			if (array_key_exists('/' . $path, $currentNamespace))
			{
				if (is_callable($currentNamespace['/' . $path]))
				{
					$routesStatus[$pIndex] = true;
					$currentNamespace = ($currentNamespace['/' . $path])();
					$finalRoutePaths[] = $path;
				}
				else
				{
					$routesStatus[$pIndex] = true;
					$routeClass = $currentNamespace['/' . $path];
					$finalRoutePaths[] = $path;
					$currentNamespace = [];
				}
			}
			else
			{
				foreach (array_keys($currentNamespace) as $key)
					if (preg_match('/\/\[\w+\]/', $key) !== 0)
					{
						if (is_callable($currentNamespace[$key]))
						{
							$routesStatus[$pIndex] = true;
							$currentNamespace = ($currentNamespace[$key])();
							$finalRoutePaths[] = $key;
							$matches[] = $path;
						}
						else
						{
							
							$routesStatus[$pIndex] = true;
							$routeClass = $currentNamespace[$key];
							$finalRoutePaths[] = $key;
							$matches[] = $path;
							$currentNamespace = [];
							break;
						}
					}
			}

			if (!$routesStatus[$pIndex]) break;
		}

		try
		{		
			$urlParams = null;
			if (!empty($matches) && !empty($lastFunctionClassFound))
			{
				$paramNames = [];
				preg_match_all('/\[(\w+?)\]/', implode('/', $finalRoutePaths ), $paramNames);			
				$urlParams = array_combine($paramNames[1], $matches);
			}

			$params = is_array($urlParams) ? $urlParams : [];

			$functionsObject = isset($lastFunctionClassFound) && class_exists($lastFunctionClassFound) 
                ? new $lastFunctionClassFound($params) 
                : new BaseFunctionsClass($params);

            if (empty($_GET[$callQueryParam]))
            {
                self::getFetcher($functionsObject, $_GET[$routeQueryParam] ?? '/', $fetchUrlsTemplate);
            }
            else
            {
                self::callMethod($functionsObject, $_GET[$callQueryParam]);
            }
		}
		catch (\Exception $e)
		{
			header($_SERVER["SERVER_PROTOCOL"] . " 500 Internal Server Error", true, 500);
			header('Content-Type: text/plain', true, 500);
			echo 'Erro 500! ' . $e->getMessage();
			exit;
		}
	}

    public static function callMethod(BaseFunctionsClass $model, string $method)
    {
        $model->__applyMiddlewares();

        $ref = new \ReflectionObject($model);

        if (!$ref->hasMethod($method))
            throw new \Exception("Função não encontrada!");

        $attrs = $ref->getMethod($method)->getAttributes(FormDataBody::class);
        if (count($attrs) < 1)
        {
            if (count($ref->getMethod($method)->getAttributes(HttpGetMethod::class)) > 0)
                $result = $model->$method($_GET);	
            else
            {
                $_POST = json_decode(file_get_contents("php://input"), true);
                $result = $model->$method($_POST);
            }
        }
        else
        {
            $result = $model->$method($_POST, $_FILES);
        }

        $ctattrs = $ref->getMethod($method)->getAttributes(ReturnsContentType::class);
        if (count($ctattrs) > 0)
        {
            header('Content-Type:' . array_pop($ctattrs)->getArguments()[0]);
            echo $result;
        }
        else
        {
            header('Content-Type:application/json');
            echo json_encode($result);
        }

        exit;
    }

    public static function getFetcher(BaseFunctionsClass $model, string $route, string $fetcherUrlTemplate)
    {
        $ref = new \ReflectionClass(get_class($model));
        header('Content-Type:text/javascript');
        
        foreach ($ref->getMethods() as $refMeth)
        {
            if(count($refMeth->getAttributes(IgnoreMethod::class)) > 0)
                continue;

            $isFormData = array_search(FormDataBody::class, array_map(fn($a) => $a->getName(), $refMeth->getAttributes())) !== false;
            $contentTypeText = !$isFormData ? ", headers: new Headers({'Content-Type': 'application/json' })" : '';
            $bodyText = !$isFormData ? "JSON.stringify(bodyData)" : 'bodyData';
            $resMethodCall = count($refMeth->getAttributes(ReturnsContentType::class)) < 1 ? 'json' : ($refMeth->getAttributes(ReturnsContentType::class)[0]->getArguments()[1] ?? 'text');
    
            if (count($refMeth->getAttributes(HttpGetMethod::class)) < 1)
            {
                $reqMethod = 'POST';
                $searchParamsVal = "''";
            }
            else
            {
                $reqMethod = 'GET';
                $searchParamsVal = "'&' + Object.entries(bodyData).map(([ k, v ]) => !Array.isArray(v) ? `\${k}=\${v}` : v.map(v2 => `\${k}[]=\${v2}`).join(`&`)).join(`&`)";
            }
    
            $bodyProp = $reqMethod === 'POST' ? ('body: ' . $bodyText . ', ') : '';
    
            $url = str_replace("{route}", $route, $fetcherUrlTemplate);
            $url = str_replace("{call}", $refMeth->name, $url);

            echo "
            export async function {$refMeth->name}(bodyData = {})
            {
                const searchParams = $searchParamsVal;
                const res = await fetch('{$url}' + searchParams, { $bodyProp method: '$reqMethod' $contentTypeText })
                return await res.{$resMethodCall}();
            }";
        }
        exit;
    }
}