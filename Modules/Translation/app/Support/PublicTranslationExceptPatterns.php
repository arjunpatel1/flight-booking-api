<?php

namespace Modules\Translation\Support;

class PublicTranslationExceptPatterns
{
    static array $patterns = [
        "auth.*",
        "validation.*",
        "pagination.*",
        "1::*.*",
        "passwords.*",
        "*::messages.*",
        "*::exceptions.*",
        "core::permissions.*",
        "support::countries.*",
        "*::enums.*",
        "support::languages.*"
    ];
}
