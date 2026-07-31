<?php

declare(strict_types=1);

namespace RelenzWorks\InfraRegister\Tests\Infrastructure;

use ArrayIterator;
use PHPUnit\Framework\TestCase;
use RelenzWorks\InfraRegister\Infrastructure\Security\LdapConfiguration;
use RelenzWorks\InfraRegister\Infrastructure\Security\NativeLdapClient;
use Symfony\Component\Ldap\Adapter\CollectionInterface;
use Symfony\Component\Ldap\Adapter\EntryManagerInterface;
use Symfony\Component\Ldap\Adapter\QueryInterface;
use Symfony\Component\Ldap\Entry;
use Symfony\Component\Ldap\Exception\ConnectionException;
use Symfony\Component\Ldap\LdapInterface;

final class NativeLdapClientTest extends TestCase
{
    public function testItFindsOneUserThroughSymfonyLdap(): void
    {
        $ldap = new FakeSymfonyLdap([
            new Entry('uid=alice,dc=example,dc=com', [
                'memberOf' => ['cn=InfraRegister Operators,dc=example,dc=com'],
            ]),
        ]);
        $client = new NativeLdapClient($ldap);

        $entry = $client->findUser(new LdapConfiguration(
            'ldap://directory.example',
            'dc=example,dc=com',
            '(uid={username})',
            'cn=service,dc=example,dc=com',
            'service-secret',
            [],
        ), 'alice*');

        self::assertSame('uid=alice,dc=example,dc=com', $entry['dn'] ?? null);
        self::assertSame(['cn=InfraRegister Operators,dc=example,dc=com'], $entry['memberOf'] ?? null);
        self::assertSame('(uid=alice\2a)', $ldap->lastQuery);
    }

    public function testItReturnsNullWhenSearchDoesNotReturnExactlyOneUser(): void
    {
        $client = new NativeLdapClient(new FakeSymfonyLdap([]));

        self::assertNull($client->findUser(
            new LdapConfiguration('ldap://directory.example', 'dc=example,dc=com', '(uid={username})', null, null, []),
            'alice',
        ));
    }

    public function testItReturnsNullWhenLdapSearchFails(): void
    {
        $client = new NativeLdapClient(new FakeSymfonyLdap([], failQuery: true));

        self::assertNull($client->findUser(
            new LdapConfiguration('ldap://directory.example', 'dc=example,dc=com', '(uid={username})', null, null, []),
            'alice',
        ));
    }

    public function testItAuthenticatesWithSymfonyLdapBind(): void
    {
        self::assertTrue((new NativeLdapClient(new FakeSymfonyLdap([])))->authenticate(
            'ldap://directory.example',
            'uid=alice,dc=example,dc=com',
            'secret',
        ));
    }

    public function testItRejectsFailedBinds(): void
    {
        self::assertFalse((new NativeLdapClient(new FakeSymfonyLdap([], failBind: true)))->authenticate(
            'ldap://directory.example',
            'uid=alice,dc=example,dc=com',
            'secret',
        ));
    }

}

final class FakeSymfonyLdap implements LdapInterface
{
    public ?string $lastQuery = null;

    /**
     * @param list<Entry> $entries
     */
    public function __construct(
        private readonly array $entries,
        private readonly bool $failBind = false,
        private readonly bool $failQuery = false,
    ) {}

    public function bind(?string $dn = null, ?string $password = null): void
    {
        if ($this->failBind) {
            throw new ConnectionException('Bind failed.');
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function query(string $dn, string $query, array $options = []): QueryInterface
    {
        $this->lastQuery = $query;

        if ($this->failQuery) {
            throw new ConnectionException('Search failed.');
        }

        return new FakeSymfonyLdapQuery($this->entries);
    }

    public function getEntryManager(): EntryManagerInterface
    {
        return new class implements EntryManagerInterface {
            public function add(Entry $entry): static
            {
                return $this;
            }

            public function update(Entry $entry): static
            {
                return $this;
            }

            public function move(Entry $entry, string $newParent): static
            {
                return $this;
            }

            public function rename(Entry $entry, string $newRdn, bool $removeOldRdn = true): static
            {
                return $this;
            }

            public function remove(Entry $entry): static
            {
                return $this;
            }
        };
    }

    public function escape(string $subject, string $ignore = '', int $flags = 0): string
    {
        return str_replace('*', '\2a', $subject);
    }
}

final readonly class FakeSymfonyLdapQuery implements QueryInterface
{
    /**
     * @param list<Entry> $entries
     */
    public function __construct(private array $entries) {}

    public function execute(): CollectionInterface
    {
        return new FakeSymfonyLdapCollection($this->entries);
    }
}

final readonly class FakeSymfonyLdapCollection implements CollectionInterface
{
    /**
     * @param list<Entry> $entries
     */
    public function __construct(private array $entries) {}

    /**
     * @return ArrayIterator<int, Entry>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->entries);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->entries[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->entries[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    public function count(): int
    {
        return count($this->entries);
    }

    public function toArray(): array
    {
        return $this->entries;
    }
}
