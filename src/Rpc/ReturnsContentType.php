<?php
namespace VictorOpusculo\PComp\Rpc;

use Attribute;

#[Attribute]
class ReturnsContentType 
{
    public function __construct(private string $contentType, private string $responseDecodeJsMethod = 'text')
    {
    }
}