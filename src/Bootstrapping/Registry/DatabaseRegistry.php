<?php

declare(strict_types=1);

namespace Strux\Bootstrapping\Registry;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Strux\Component\Config\Config;
use Strux\Component\Database\Database;
use Strux\Component\Database\Seeder\SeederRunner;

class DatabaseRegistry extends ServiceRegistry
{
    public function build(): void
    {
        $this->container->singleton(
            Database::class,
            static fn(ContainerInterface $c) => new Database(
                config: $c->get(Config::class),
                logger: $c->get(LoggerInterface::class)
            )
        );
        $this->container->singleton(
            PDO::class,
            static fn(ContainerInterface $c) => $c->get(Database::class)->getConnection()
        );
        $this->container->singleton(
            SeederRunner::class,
            static fn(ContainerInterface $c) => new SeederRunner($c->get(PDO::class), $c)
        );

        $this->container->transient('db.query', static function () {
            return (new \Strux\Component\Database\ORM\Adhoc())->query();
        });
    }
}