<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Demo;

use Vasoft\Joke\Container\ServiceContainer;
use Vasoft\Joke\Db\Sql\ConnectionManager;
use Vasoft\Joke\Http\Cookies\CookieCollection;
use Vasoft\Joke\Http\Csrf\CsrfTokenManager;
use Vasoft\Joke\Http\HttpRequest;
use Vasoft\Joke\Http\Response\HtmlPageResponse;
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Http\Response\RedirectResponse;
use Vasoft\Joke\Http\Response\ResponseBuilder;
use Vasoft\Joke\Http\Response\ResponseStatus;
use Vasoft\Joke\Support\FileSystem;

class DemoController
{
    public function add(
        ServiceContainer $container,
        HttpRequest $request,
        ConnectionManager $manager,
    ): RedirectResponse {
        $logger = $container->make(static fn(FileSystem $fs) => new DemoLogger($fs, 'demo'));
        $connection = $manager->loggable($logger);
        $this->ensureTable($connection);

        try {
            $connection->beginTransaction();
            $this->addRow($request, $connection);
            $connection->commit();

            return $container->make(
                RedirectResponse::class,
                [
                    'redirectUrl' => '/',
                    'status' => ResponseStatus::SEE_OTHER,
                    'cookies' => $container->make(CookieCollection::class, ['isSecureConnection' => false]),
                ],
            );
        } catch (\Throwable $exception) {
            if ($connection->transactionLevel > 0) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function index(
        ServiceContainer $container,
        HttpRequest $request,
        ConnectionManager $manager,
        ResponseBuilder $builder,
    ): HtmlPageResponse {
        $result = $builder->buildResponse(HtmlPageResponse::class);
        $tokenName = CsrfTokenManager::CSRF_TOKEN_NAME;
        $tokenValue = $request->session->get(CsrfTokenManager::CSRF_TOKEN_NAME);

        $logger = $container->make(static fn(FileSystem $fs) => new DemoLogger($fs, 'demo'));
        $connection = $manager->loggable($logger);
        $this->ensureTable($connection);
        $connection->beginTransaction();
        $data = $this->get($connection);
        $connection->commit();
        $body = $this->render($data);

        $result->setBody(
            <<<HTML
                <form method="post">
                    <input type="text" name="name" placeholder="Введите имя">
                    <input type="text" name="age" placeholder="Введите возраст">
                    <button type="submit">Сохранить</button>
                    <input type="hidden" name="{$tokenName}" value="{$tokenValue}">
                </form>
                <table>
                    <thead>
                    <tr><th>ID</th><th>Имя</th><th>Возраст</th></tr>
                    </thead>
                    <tbody>
                    {$body}
                    </tbody>
                </table>
                HTML,
        );

        return $result;
    }

    private function ensureTable(ConnectionInterface $connection): void
    {
        $connection->execute(
            <<<'SQL'
                    CREATE TABLE IF NOT EXISTS `users` (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL,
                        age INTEGER NOT NULL
                    )
                SQL,
        );
    }

    private function addRow(HttpRequest $request, ConnectionInterface $connection): void
    {
        $name = $request->post->getString('name', '');
        $age = $request->post->getInt('age', 0);
        if ('' === $name || 0 === $age) {
            return;
        }
        $connection->execute(
            'INSERT INTO users (name, age) VALUES (:name, :age)',
            ['name' => $name, 'age' => $age],
        );
    }

    private function get(ConnectionInterface $connection): array
    {
        return $connection->query('SELECT * FROM users')->all();
    }

    private function render(array $data): string
    {
        $html = '';
        foreach ($data as $row) {
            $html .= "<tr><td>{$row['id']}</td><td>{$row['name']}</td><td>{$row['age']}</td></tr>";
        }

        return $html;
    }
}
