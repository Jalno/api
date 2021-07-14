<?php
namespace Jalno\API;

use Jalno\Lumen\Packages\PackageAbstract;

class Package extends PackageAbstract
{
    public function basePath(): string
    {
        return __DIR__;
    }

    public function getNamespace(): string
    {
        return __NAMESPACE__;
    }
}
