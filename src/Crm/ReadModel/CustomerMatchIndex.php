<?php

declare(strict_types=1);

namespace App\Crm\ReadModel;

use App\Crm\ValueObject\SheetText;

/**
 * `CustomerMatchQuery`'s results as lookups, so `CustomerClassifier` stays pure (#48 R5): by folded name,
 * by website host (`SheetText::host()`, the sheet's own rule), by email and by similar name. Built once
 * per run; a unit test builds it from hand-made views.
 */
final readonly class CustomerMatchIndex
{
    /** @var array<string, list<CustomerMatchVenue>> */
    private array $venuesByKey;

    /** @var array<string, list<CustomerMatchCompany>> */
    private array $companiesByKey;

    /** @var array<string, list<CustomerMatchVenue|CustomerMatchCompany>> */
    private array $byHost;

    /** @var array<string, list<CustomerMatchCompany>> */
    private array $companiesByEmail;

    /** @var array<string, true> */
    private array $contactEmails;

    /** @var array<string, list<CustomerMatchVenue|CustomerMatchCompany>> */
    private array $byName;

    /**
     * @param list<CustomerMatchVenue>    $venues
     * @param list<CustomerMatchCompany>  $companies
     * @param list<string>                $contactEmails
     * @param array<string, list<string>> $similar       `CustomerMatchQuery::similar()`
     */
    public function __construct(
        array $venues,
        array $companies,
        array $contactEmails,
        private array $similar,
    ) {
        $venuesByKey = [];
        $companiesByKey = [];
        $byHost = [];
        $companiesByEmail = [];
        $byName = [];
        foreach ($venues as $venue) {
            $venuesByKey[$venue->key][] = $venue;
            $byName[$venue->name][] = $venue;
            $host = SheetText::host($venue->website);
            if (null !== $host) {
                $byHost[$host][] = $venue;
            }
        }
        foreach ($companies as $company) {
            $companiesByKey[$company->key][] = $company;
            $byName[$company->name][] = $company;
            $host = SheetText::host($company->website);
            if (null !== $host) {
                $byHost[$host][] = $company;
            }
            if (null !== $company->email && '' !== $company->email) {
                $companiesByEmail[mb_strtolower($company->email)][] = $company;
            }
        }

        $this->venuesByKey = $venuesByKey;
        $this->companiesByKey = $companiesByKey;
        $this->byHost = $byHost;
        $this->companiesByEmail = $companiesByEmail;
        $this->contactEmails = array_fill_keys(array_map(mb_strtolower(...), $contactEmails), true);
        $this->byName = $byName;
    }

    /**
     * @return list<CustomerMatchVenue>
     */
    public function venuesByKey(string $key): array
    {
        return '' === $key ? [] : $this->venuesByKey[$key] ?? [];
    }

    /**
     * @return list<CustomerMatchCompany>
     */
    public function companiesByKey(string $key): array
    {
        return '' === $key ? [] : $this->companiesByKey[$key] ?? [];
    }

    /**
     * @param string $host as `SheetText::host()` gives it
     *
     * @return list<CustomerMatchVenue|CustomerMatchCompany>
     */
    public function byHost(string $host): array
    {
        return $this->byHost[$host] ?? [];
    }

    /**
     * @return list<CustomerMatchCompany> the companies with this email, in any case
     */
    public function byEmail(string $email): array
    {
        return $this->companiesByEmail[mb_strtolower($email)] ?? [];
    }

    /** A contact has this email, in any case. The contact is a person, so it is never named. */
    public function isContactEmail(string $email): bool
    {
        return isset($this->contactEmails[mb_strtolower($email)]);
    }

    /**
     * The venues and companies whose name is similar to this sheet name, as `CustomerMatchQuery::similar()`
     * found them for it.
     *
     * @return list<CustomerMatchVenue|CustomerMatchCompany>
     */
    public function similarTo(string $name): array
    {
        $found = [];
        foreach ($this->similar[$name] ?? [] as $similarName) {
            foreach ($this->byName[$similarName] ?? [] as $match) {
                $found[] = $match;
            }
        }

        return $found;
    }
}
