<?php

declare(strict_types=1);

namespace Dbots\Bootstrap;

use PDO;

use function dirname;
use function is_dir;
use function mkdir;

final class PdoFactory
{
    public static function create(string $databasePath): PDO
    {
        if (!is_dir(dirname($databasePath))) {
            mkdir(dirname($databasePath), 0o775, true);
        }

        return new PDO('sqlite:' . $databasePath);
    }
}
