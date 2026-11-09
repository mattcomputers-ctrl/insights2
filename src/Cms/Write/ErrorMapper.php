<?php

declare(strict_types=1);

namespace PII\Cms\Write;

/**
 * Turns CMS's ResultText into something a person can act on.
 *
 * ResultText is XML like
 *   <Result Level="16" State="1" Procedure="ProcessXMLRequest" Line="171"
 *           Message="Order 186936 is currently locked by pic\x at CS-PC"/>
 * The patterns below come from the failed requests in the CMS log
 * (docs/PARITY.md lists them per action). Anything unrecognised is shown
 * as CMS worded it.
 */
final class ErrorMapper
{
    /** @var list<array{0:string,1:string}> regex => readable template ($1.. substituted) */
    private const PATTERNS = [
        ['/^Order (\d+) is currently locked by (\S+) at (\S+)/i',
            'Order $1 is open for editing by $2 on $3. Ask them to close it in CMS, then try again.'],
        ['/is currently locked by (\S+) at (\S+)/i',
            'This record is open for editing by $1 on $2. Ask them to close it, then try again.'],
        ['/(has been|was) (changed|modified|updated) by another user/i',
            'Someone else changed this record while you were working. Reload the page and try again.'],
        ['/version/i',
            'This record changed in CMS since the page was loaded. Reload and try again. (CMS said: $0)'],
        ['/^Quantity of the item \'([^\']+)\' is negative/i',
            'Not enough stock: this would take item $1 below zero at that location.'],
        ['/deadlock/i',
            'CMS was busy (deadlock) and rolled the request back. Nothing was saved; try again.'],
        ['/credit/i',
            'Credit check: $0'],
        ['/permission|not authori[sz]ed|access denied/i',
            'CMS refused the request for this user: $0'],
    ];

    /** Extract the Message attribute from a ResultText document (or return the text unchanged). */
    public static function message(?string $resultText): string
    {
        $resultText = trim((string) $resultText);
        if ($resultText === '') {
            return '';
        }
        if (str_starts_with($resultText, '<')) {
            $doc = new \DOMDocument();
            if (@$doc->loadXML($resultText) && $doc->documentElement !== null
                && $doc->documentElement->hasAttribute('Message')) {
                return $doc->documentElement->getAttribute('Message');
            }
        }
        return $resultText;
    }

    /** Readable explanation for a raw CMS message. */
    public static function explain(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'CMS rejected the request without giving a reason. Nothing was saved.';
        }
        foreach (self::PATTERNS as [$regex, $template]) {
            if (preg_match($regex, $message, $m)) {
                return preg_replace_callback('/\$(\d)/', fn ($x) => $m[(int) $x[1]] ?? '', $template);
            }
        }
        return 'CMS rejected the request: ' . $message;
    }

    public static function fromResultText(?string $resultText): string
    {
        return self::explain(self::message($resultText));
    }
}
