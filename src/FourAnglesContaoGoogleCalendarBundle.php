<?php

declare(strict_types=1);

namespace FourAngles\ContaoGoogleCalendarBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

class FourAnglesContaoGoogleCalendarBundle extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
