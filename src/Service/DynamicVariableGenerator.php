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

    /**
     * Returns the generated value, or null if the name is not a known dynamic variable.
     */
    public function generate(string $name): ?string
    {
        // strip a single leading $
        $key = ltrim($name, '$');

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
}
