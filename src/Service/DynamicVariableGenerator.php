<?php

namespace App\Service;

/**
 * Generates values for Postman-style dynamic variables ({{$randomEmail}} etc.).
 * A fresh value is produced on every call, matching Postman's behaviour.
 */
class DynamicVariableGenerator
{
    private const FIRST_NAMES = ['Ada', 'Liam', 'Mia', 'Noah', 'Elif', 'Defne', 'Can', 'Zara', 'Leo', 'Ece', 'Aria', 'Kerem', 'Nora', 'Emir', 'Sofia', 'Aylin'];
    private const LAST_NAMES = ['Yilmaz', 'Demir', 'Kaya', 'Smith', 'Johnson', 'Brown', 'Sahin', 'Celik', 'Arslan', 'Dogan', 'Wright', 'Lopez'];
    private const WORDS = ['lorem', 'ipsum', 'dolor', 'amet', 'magna', 'aliqua', 'tempor', 'labore', 'fugit', 'nisi', 'velit', 'cillum'];
    private const CITIES = ['Istanbul', 'Berlin', 'London', 'Paris', 'Madrid', 'Ankara', 'Lisbon', 'Vienna', 'Oslo', 'Dublin'];
    private const COUNTRIES = ['Turkey', 'Germany', 'France', 'Spain', 'Italy', 'Sweden', 'Norway', 'Portugal', 'Ireland'];
    private const COMPANIES = ['Acme', 'Globex', 'Initech', 'Umbrella', 'Hooli', 'Soylent', 'Vehement', 'Massive Dynamic'];
    private const COLORS = ['red', 'green', 'blue', 'yellow', 'purple', 'cyan', 'magenta', 'orange'];

    /** Discoverable catalog: primary token name → short description (TR). */
    public const BUILTINS = [
        'guid' => 'UUID (v4)',
        'timestamp' => 'Unix zaman damgası',
        'isoTimestamp' => 'ISO 8601 zaman (UTC)',
        'isoDate' => 'ISO tarih (YYYY-MM-DD)',
        'randomInt' => 'Rastgele tam sayı (0–1000)',
        'randomPrice' => 'Rastgele fiyat (0.00)',
        'randomBoolean' => 'true / false',
        'randomEmail' => 'Rastgele e-posta',
        'randomUserName' => 'Rastgele kullanıcı adı',
        'randomFirstName' => 'Rastgele ad',
        'randomLastName' => 'Rastgele soyad',
        'randomFullName' => 'Rastgele ad soyad',
        'randomPhoneNumber' => 'Rastgele telefon',
        'randomCompanyName' => 'Rastgele şirket',
        'randomCity' => 'Rastgele şehir',
        'randomCountry' => 'Rastgele ülke',
        'randomColor' => 'Rastgele renk',
        'randomWord' => 'Rastgele kelime',
        'randomWords' => 'Rastgele kelimeler (3)',
        'randomIP' => 'Rastgele IPv4',
    ];

    /**
     * Returns the generated value, or null if the name is not a known dynamic variable.
     */
    /**
     * Workspace-managed factories for the current run, keyed by name.
     *
     * @var array<string, array{kind: string, config: array<string, mixed>}>
     */
    private array $factories = [];

    private int $depth = 0;

    /**
     * Registers the workspace's data factories for this run. They resolve as
     * {{$name}}, checked after the built-ins.
     *
     * @param array<string, array{kind: string, config: array<string, mixed>}> $factories
     */
    public function setFactories(array $factories): void
    {
        $this->factories = $factories;
    }

    /**
     * The discoverable built-in catalog with a fresh sample value for each.
     *
     * @return array<int, array{name: string, token: string, description: string, sample: string}>
     */
    public function builtins(): array
    {
        $out = [];
        foreach (self::BUILTINS as $name => $desc) {
            $out[] = [
                'name' => $name,
                'token' => '{{$' . $name . '}}',
                'description' => $desc,
                'sample' => (string) $this->builtin($name),
            ];
        }

        return $out;
    }

    public function generate(string $name): ?string
    {
        // strip a single leading $
        $key = ltrim($name, '$');

        $builtin = $this->builtin($key);
        if (null !== $builtin) {
            return $builtin;
        }

        return isset($this->factories[$key]) ? $this->fromFactory($this->factories[$key]) : null;
    }

    private function builtin(string $key): ?string
    {
        return match ($key) {
            'guid', 'randomUUID' => $this->uuid(),
            'timestamp' => (string) time(),
            'isoTimestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'randomInt' => (string) random_int(0, 1000),
            'randomFirstName' => self::FIRST_NAMES[array_rand(self::FIRST_NAMES)],
            'randomLastName' => self::LAST_NAMES[array_rand(self::LAST_NAMES)],
            'randomFullName' => self::FIRST_NAMES[array_rand(self::FIRST_NAMES)] . ' ' . self::LAST_NAMES[array_rand(self::LAST_NAMES)],
            'randomUserName' => strtolower(self::FIRST_NAMES[array_rand(self::FIRST_NAMES)]) . random_int(10, 9999),
            'randomEmail' => strtolower(self::FIRST_NAMES[array_rand(self::FIRST_NAMES)] . '.' . self::LAST_NAMES[array_rand(self::LAST_NAMES)]) . random_int(1, 999) . '@example.com',
            'randomPhoneNumber' => '+' . random_int(1, 99) . random_int(1000000000, 9999999999),
            'randomCompanyName' => self::COMPANIES[array_rand(self::COMPANIES)],
            'randomCity' => self::CITIES[array_rand(self::CITIES)],
            'randomCountry' => self::COUNTRIES[array_rand(self::COUNTRIES)],
            'randomColor' => self::COLORS[array_rand(self::COLORS)],
            'randomBoolean' => 0 === random_int(0, 1) ? 'false' : 'true',
            'randomWord' => self::WORDS[array_rand(self::WORDS)],
            'randomWords' => implode(' ', array_map(fn () => self::WORDS[array_rand(self::WORDS)], range(1, 3))),
            'randomIP', 'randomIPV4' => sprintf('%d.%d.%d.%d', random_int(1, 255), random_int(0, 255), random_int(0, 255), random_int(1, 255)),
            'randomPrice' => number_format(random_int(100, 99999) / 100, 2, '.', ''),
            'isoDate', 'randomDatePast' => gmdate('Y-m-d'),
            default => null,
        };
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr((\ord($data[6]) & 0x0F) | 0x40);
        $data[8] = \chr((\ord($data[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @param array{kind: string, config: array<string, mixed>} $factory
     */
    private function fromFactory(array $factory): string
    {
        $config = $factory['config'] ?? [];

        return match ($factory['kind'] ?? '') {
            'oneOf' => $this->fromOneOf($config),
            'intRange' => (string) random_int((int) ($config['min'] ?? 0), max((int) ($config['min'] ?? 0), (int) ($config['max'] ?? 100))),
            'pattern' => $this->fromPattern((string) ($config['pattern'] ?? '')),
            'template' => $this->renderTemplate((string) ($config['template'] ?? '')),
            default => '',
        };
    }

    /** @param array<string, mixed> $config */
    private function fromOneOf(array $config): string
    {
        $values = array_values(array_filter(
            array_map(static fn ($v): string => trim((string) $v), (array) ($config['values'] ?? [])),
            static fn (string $v): bool => '' !== $v,
        ));

        return [] === $values ? '' : (string) $values[array_rand($values)];
    }

    /**
     * # → digit, A → A-Z, a → a-z, * → alnum; anything else is literal.
     */
    private function fromPattern(string $pattern): string
    {
        $out = '';
        $len = \strlen($pattern);
        for ($i = 0; $i < $len; ++$i) {
            $out .= match ($pattern[$i]) {
                '#' => (string) random_int(0, 9),
                'A' => \chr(random_int(65, 90)),
                'a' => \chr(random_int(97, 122)),
                '*' => '0123456789abcdefghijklmnopqrstuvwxyz'[random_int(0, 35)],
                default => $pattern[$i],
            };
        }

        return $out;
    }

    /**
     * Resolves {{$token}} placeholders inside a template (built-ins + factories),
     * so factories can compose — e.g. "test+{{$guid}}@zotlo.com". Guarded against
     * runaway factory recursion.
     */
    private function renderTemplate(string $template): string
    {
        if ($this->depth > 8) {
            return $template;
        }
        ++$this->depth;
        $out = preg_replace_callback(
            '/\{\{\s*(\$[\w.\-]+)\s*\}\}/',
            fn (array $m): string => $this->generate($m[1]) ?? $m[0],
            $template,
        );
        --$this->depth;

        return (string) $out;
    }
}
