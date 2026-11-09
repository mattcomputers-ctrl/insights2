<?php

declare(strict_types=1);

namespace PII\Cms\Write\Ops\MasterData;

use PII\Cms\Write\CmsReader;
use PII\Cms\Write\WriteRefused;

/** Reading what a user typed for a customer. */
final class EntityInput
{
    public const CODE_MAX = 20;

    /** Address fields: the label shown to the user and the longest text CMS keeps. */
    public const FIELDS = [
        'Name'      => ['label' => 'Name', 'max' => 255, 'input' => 'name'],
        'AddrLine1' => ['label' => 'Address line 1', 'max' => 255, 'input' => 'line1'],
        'AddrLine2' => ['label' => 'Address line 2', 'max' => 255, 'input' => 'line2'],
        'City'      => ['label' => 'City', 'max' => 255, 'input' => 'city'],
        'State'     => ['label' => 'State', 'max' => 2, 'input' => 'state'],
        'ZipCode'   => ['label' => 'Zip code', 'max' => 20, 'input' => 'zip'],
        'Email'     => ['label' => 'Email', 'max' => 100, 'input' => 'email'],
        'Phone'     => ['label' => 'Phone', 'max' => 30, 'input' => 'phone'],
    ];

    /** Fields a customer can be without; the others cannot be emptied here. */
    public const OPTIONAL = ['AddrLine2', 'Email', 'Phone'];

    /** A customer code nothing in CMS has yet: upper case, plain characters, at most 20. */
    public static function newCode(CmsReader $cms, mixed $v): string
    {
        $s = strtoupper(self::text($v));
        if ($s === '') {
            throw new WriteRefused('Enter a code for the ship-to.');
        }
        if (!preg_match('/^[\x20-\x7E]+$/', $s)) {
            throw new WriteRefused('The code can only have plain letters, digits and punctuation.');
        }
        if (strlen($s) > self::CODE_MAX) {
            throw new WriteRefused('The code is too long (limit ' . self::CODE_MAX . ' characters).');
        }
        if (!EntityState::codeFree($cms, $s)) {
            throw new WriteRefused("The code {$s} is already used in CMS (customers, suppliers and carriers share their codes).");
        }
        return $s;
    }

    /**
     * The address fields typed on a form, tidied and checked.
     *
     * @param array<string,mixed> $input keyed by the `input` names of FIELDS
     * @return array<string,string> keyed by the CMS field names; blank fields are empty strings
     */
    public static function address(CmsReader $cms, array $input): array
    {
        $out = [];
        foreach (self::FIELDS as $field => $def) {
            $value = self::text($input[$def['input']] ?? '');
            if ($field === 'State') {
                $value = strtoupper($value);
            }
            if (mb_strlen($value) > $def['max']) {
                throw new WriteRefused("{$def['label']} is too long (limit {$def['max']} characters).");
            }
            $out[$field] = $value;
        }
        if ($out['State'] !== '' && !EntityState::stateKnown($cms, $out['State'])) {
            throw new WriteRefused("CMS has no state or province with the code {$out['State']}.");
        }
        if ($out['Email'] !== '' && !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $out['Email'])) {
            throw new WriteRefused('The email address does not look right.');
        }
        return $out;
    }

    /** A freight account: free text of at most 20 characters, may be blank. */
    public static function freightAccount(mixed $v): string
    {
        $s = self::text($v);
        if (strlen($s) > 20) {
            throw new WriteRefused('The freight account is too long (limit 20 characters).');
        }
        return $s;
    }

    private static function text(mixed $v): string
    {
        return trim(preg_replace('/\s+/', ' ', is_scalar($v) ? (string) $v : '') ?? '');
    }
}
