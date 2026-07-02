<?php

declare(strict_types=1);

namespace Wenbo\ReqResCrypto\Laravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class ReqResEncrypt
{
}
