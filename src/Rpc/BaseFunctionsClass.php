<?php
namespace VictorOpusculo\PComp\Rpc;

class BaseFunctionsClass
{
    #[IgnoreMethod]
    public function __construct(?array $properties = null)
    {
        if (isset($properties))
            foreach ($properties as $key => $value) 
            {
                $this->$key = $value;
            }
    }

    /** @var callable[] */
    protected array $middlewares = [];

    #[IgnoreMethod]
    public function __applyMiddlewares() : void
    {
        foreach ($this->middlewares as $m)
            $m();
    }
}