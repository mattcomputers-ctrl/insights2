<?php

declare(strict_types=1);

namespace PII\Modules\Shipping;

use PII\Cms\Write\CmsWriter;
use PII\Cms\Write\LiveCmsReader;
use PII\Cms\Write\Ops\Shipping\CreateWaybill;
use PII\Cms\Write\Ops\Shipping\ShippingState;
use PII\Cms\Write\WriteRefused;
use PII\Controllers\CmsWriteController;
use PII\Core\CMSUnavailableException;
use PII\Core\CSRF;
use PII\Services\Settings;

require_once dirname(__DIR__, 2) . '/Helpers/layout.php';

/**
 * Shipping entry: ship assembled orders on a new waybill, finish or
 * reject a waybill, record printed labels, and the packing slip and
 * invoice print pages.
 *
 * Nothing here writes to CMS directly. Each POST hands a proposal to the
 * shared confirm step (CmsWriteController), which shows the exact
 * requests and only sends them after the user confirms.
 */
class ShippingEntryController
{
    /** Ready orders, then (with orders chosen) their containers, weights and freight charges. */
    public function newWaybill(): void
    {
        if (!CmsWriter::forCurrentUser()->allowed('ship.create_waybill')) {
            render_not_found();
        }
        $ready = [];
        $unfinished = [];
        $chosen = [];
        $picked = [];
        $error = null;
        $problem = null;
        try {
            $cms = new LiveCmsReader();
            $ready = ShippingState::readyOrders($cms);
            $unfinished = ShippingState::unfinished($cms);
            $raw = $_GET['orders'] ?? '';
            if ((is_array($raw) && $raw !== []) || (is_string($raw) && trim($raw) !== '')) {
                try {
                    $picked = CreateWaybill::orderNumbers($raw);
                } catch (WriteRefused $e) {
                    $problem = $e->getMessage();
                }
                $byOrder = array_column($ready, null, 'Ordr');
                foreach ($picked as $id) {
                    if (!isset($byOrder[$id])) {
                        $problem ??= "Order {$id} has nothing assembled and ready to ship.";
                        continue;
                    }
                    $chosen[] = $byOrder[$id] + ['containers' => ShippingState::assemblies($cms, $id)];
                }
                if ($problem !== null) {
                    $chosen = [];
                }
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }

        layout('shipping::new', [
            'pageTitle'  => 'Ship orders',
            'ready'      => $ready,
            'unfinished' => $unfinished,
            'chosen'     => $chosen,
            'picked'     => $picked,
            'problem'    => $problem,
            'error'      => $error,
            'maxOrders'  => CreateWaybill::MAX_ORDERS,
            'mode'       => CmsWriter::forCurrentUser()->mode(),
        ], 'shipping');
    }

    public function create(): void
    {
        CSRF::validateRequest();
        $orders = array_values(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            (array) ($_POST['orders'] ?? [])
        ), static fn (string $v): bool => $v !== ''));
        $weights = [];
        foreach ((array) ($_POST['weights'] ?? []) as $location => $lb) {
            if (ctype_digit((string) $location) && !is_array($lb)) {
                $weights[(int) $location] = trim((string) $lb);
            }
        }
        $freight = [];
        foreach ((array) ($_POST['freight'] ?? []) as $ordr => $charge) {
            if (ctype_digit((string) $ordr) && !is_array($charge)) {
                $freight[(int) $ordr] = trim((string) $charge);
            }
        }
        $back = '/shipping/new' . ($orders !== [] ? '?orders=' . rawurlencode(implode(',', $orders)) : '');
        CmsWriteController::propose('ship.create_waybill', ['orders' => $orders, 'weights' => $weights, 'freight' => $freight], $back);
    }

    public function finish(string $waybill): void
    {
        CSRF::validateRequest();
        CmsWriteController::propose('ship.resume_waybill', ['waybill' => (int) $waybill], '/shipping/' . (int) $waybill);
    }

    public function reject(string $waybill): void
    {
        CSRF::validateRequest();
        CmsWriteController::propose('ship.reject_waybill', ['waybill' => (int) $waybill], '/shipping/' . (int) $waybill);
    }

    public function labels(string $waybill): void
    {
        CSRF::validateRequest();
        CmsWriteController::propose('ship.label_address', ['waybill' => (int) $waybill], '/shipping/' . (int) $waybill);
    }

    /** Packing slip for one order's shipment on a waybill (a CMS change set). */
    public function packingSlip(string $changeSet): void
    {
        $changeSet = (int) $changeSet;
        $head = null;
        $lines = [];
        $error = null;
        try {
            $cms = new LiveCmsReader();
            $head = $cms->fetch("
                SELECT p.ChangeSet, p.Ordr, p.TransDocument, CONVERT(VARCHAR(10), p.DateShipped, 23) AS DateShipped,
                       p.ShipToName, p.ShipToAddrLine1, p.ShipToAddrLine2, p.ShipToAddrLine3, p.ShipToCityStateZip,
                       p.ShipToCountry, p.ShipToContact, p.ShipToPhone,
                       p.BillToName, p.BillToAddrLine1, p.BillToAddrLine2, p.BillToAddrLine3, p.BillToCityStateZip, p.BillToCountry,
                       CONVERT(VARCHAR(10), p.Dateordered, 23) AS DateOrdered, CONVERT(VARCHAR(10), p.DateRequired, 23) AS DateRequired,
                       p.Incoterms, p.ShipViaName, p.FreightService, p.PlacedBy, p.PoNumber,
                       CONVERT(NVARCHAR(2000), p.ShippingMemo) AS ShippingMemo, css.Waybill
                FROM CMS.dbo.PackingSlipReport p
                LEFT JOIN CMS.dbo.ChangeSetShipment css ON css.ChangeSet = p.ChangeSet
                WHERE p.ChangeSet = ?", [$changeSet]);
            if ($head !== null) {
                $lines = self::slipLines($cms, $changeSet);
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }
        if ($head === null && $error === null) {
            render_not_found();
        }
        layout('shipping::packing_slip', [
            'pageTitle' => 'Packing slip' . ($head !== null ? ' for order ' . (int) $head['Ordr'] : ''),
            'head'      => $head,
            'lines'     => $lines,
            'error'     => $error,
            'logo'      => (string) (Settings::get('coa.logo') ?? ''),
        ], 'shipping');
    }

    /** Invoice as CMS recorded it: shows prices, so it has its own permission. */
    public function invoice(string $trans): void
    {
        require_permission('shipping.invoice');
        $trans = (int) $trans;
        $head = null;
        $lines = [];
        $taxes = [];
        $error = null;
        try {
            $cms = new LiveCmsReader();
            $head = $cms->fetch("
                SELECT r.Trans, r.Invoice, r.Waybill, r.Ordr, r.Context,
                       CONVERT(VARCHAR(10), r.DateShipped, 23) AS DateShipped, CONVERT(VARCHAR(10), r.DateRequired, 23) AS DateRequired,
                       r.PoNumber, r.ShipViaName, r.Incoterms, r.Reference,
                       r.DivName, r.DivAddrLine1, r.DivAddrLine2, r.DivAddrLine3, r.DivCity, r.DivState, r.DivZipCode, r.DivPhone,
                       r.ShipToName, r.ShipToAddrLine1, r.ShipToAddrLine2, r.ShipToAddrLine3, r.ShipToCityStateZip, r.ShipToCountry,
                       r.BillToName, r.BillToAddrLine1, r.BillToAddrLine2, r.BillToAddrLine3, r.BillToCityStateZip, r.BillToCountry,
                       r.SalesmanName, r.TermsDescription, r.Currency, r.Amount, r.FreightCharge, r.TotalAmount, r.NetAmountDue,
                       CONVERT(NVARCHAR(2000), r.InvoiceMemo) AS InvoiceMemo, i.IsReversed
                FROM CMS.dbo.InvoiceReport r
                LEFT JOIN CMS.dbo.Invoices i ON i.Trans = r.Trans
                WHERE r.Trans = ? AND r.Context = 'CI'", [$trans]);
            if ($head !== null) {
                $lines = $cms->fetchAll("
                    SELECT d.ItemCode, d.TheirCode, CONVERT(NVARCHAR(400), d.ItemDescription) AS ItemDescription,
                           d.QtyReqd, d.QtyShipped, d.Unit, d.Price, d.PricingUnit, d.Value,
                           CONVERT(VARCHAR(1000), d.OrdDetailComment) AS OrdDetailComment
                    FROM CMS.dbo.InvoiceDetailReport d
                    WHERE d.Trans = ?
                    ORDER BY d.OrdDetail, d.InvoiceDetailReport", [$trans]);
                $taxes = $cms->fetchAll("
                    SELECT t.TaxNumber, t.TaxDescription, t.TaxAmount
                    FROM CMS.dbo.InvoiceTaxReport t
                    WHERE t.Trans = ? AND t.TaxAmount IS NOT NULL AND t.TaxAmount <> 0
                    ORDER BY t.TaxNumber", [$trans]);
            }
        } catch (CMSUnavailableException $e) {
            $error = $e->friendlyMessage();
        } catch (\Throwable $e) {
            $error = 'CMS query failed: ' . $e->getMessage();
        }
        if ($head === null && $error === null) {
            render_not_found();
        }
        layout('shipping::invoice', [
            'pageTitle' => 'Invoice' . ($head !== null ? ' ' . (string) ($head['Invoice'] ?? '') : ''),
            'head'      => $head,
            'lines'     => $lines,
            'taxes'     => $taxes,
            'error'     => $error,
            'logo'      => (string) (Settings::get('coa.logo') ?? ''),
        ], 'shipping');
    }

    /**
     * One row per order line shipped, with the containers it went in.
     *
     * @return list<array<string,mixed>>
     */
    private static function slipLines(LiveCmsReader $cms, int $changeSet): array
    {
        $out = [];
        foreach ($cms->fetchAll("
            SELECT sd.OrdDetail, sd.ItemCode, sd.ItemName, CONVERT(NVARCHAR(400), sd.Description) AS Description,
                   sd.Unit, sd.QtyOrdered, sd.QtyShipped, CONVERT(NVARCHAR(1000), sd.Comment) AS Comment, l.LocationCode
            FROM CMS.dbo.ShipmentDetails sd
            LEFT JOIN CMS.dbo.InvMovementDtl d ON d.InvMovementDtl = sd.InvMovementDtl
            LEFT JOIN CMS.dbo.Location l ON l.Location = d.Location
            WHERE sd.ChangeSet = ?
            ORDER BY sd.OrdDetail, sd.InvMovementDtl", [$changeSet]) as $r) {
            $key = (string) ($r['OrdDetail'] ?? ('i' . $r['ItemCode']));
            $out[$key] ??= [
                'ItemCode'    => (string) ($r['ItemCode'] ?? ''),
                'ItemName'    => (string) ($r['ItemName'] ?? ''),
                'Description' => (string) ($r['Description'] ?? ''),
                'Unit'        => (string) ($r['Unit'] ?? ''),
                'QtyOrdered'  => (float) ($r['QtyOrdered'] ?? 0),
                'QtyShipped'  => 0.0,
                'Comment'     => trim((string) ($r['Comment'] ?? '')),
                'Containers'  => [],
            ];
            $out[$key]['QtyShipped'] += (float) ($r['QtyShipped'] ?? 0);
            $code = trim((string) ($r['LocationCode'] ?? ''));
            if ($code !== '' && !in_array($code, $out[$key]['Containers'], true)) {
                $out[$key]['Containers'][] = $code;
            }
        }
        return array_values($out);
    }
}
