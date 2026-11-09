<?php

declare(strict_types=1);

namespace PII\Cms\Write;

use PII\Cms\Write\Ops\Purchasing\CompletePurchaseOrder;
use PII\Cms\Write\Ops\Purchasing\CreatePurchaseOrder;
use PII\Cms\Write\Ops\Purchasing\EditPurchaseOrder;
use PII\Cms\Write\Ops\Purchasing\ReceiveLine;
use PII\Cms\Write\Ops\Purchasing\ReverseReceipt;
use PII\Cms\Write\Ops\Qc\ApproveSublot;
use PII\Cms\Write\Ops\Qc\RejectSublot;
use PII\Cms\Write\Ops\Qc\SaveTestResults;
use PII\Cms\Write\Ops\Sales\CompleteOrder;
use PII\Cms\Write\Ops\Sales\CompleteSale;
use PII\Cms\Write\Ops\Sales\CreateOrder;
use PII\Cms\Write\Ops\Sales\EditOrder;
use PII\Cms\Write\Ops\Sales\ReleaseOrder;

/**
 * The write allowlist. An operation that is not listed here cannot be
 * previewed or sent, whatever the caller asks for. Each entry names the
 * one permission that unlocks it; modules surface those permissions in
 * the group editor through permissionsFor().
 */
final class OperationRegistry
{
    /** @var null|array<string,OperationDef> */
    private static ?array $defs = null;

    /** @return array<string,OperationDef> */
    public static function all(): array
    {
        if (self::$defs === null) {
            self::$defs = [];
            foreach (self::definitions() as $def) {
                self::$defs[$def->key] = $def;
            }
        }
        return self::$defs;
    }

    public static function get(string $key): ?OperationDef
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string,string> permission key => label, for Module::permissions() */
    public static function permissionsFor(string $module): array
    {
        $out = [];
        foreach (self::all() as $def) {
            if ($def->module === $module) {
                $out[$def->permission] = 'Write to CMS: ' . $def->label;
            }
        }
        return $out;
    }

    /** @return list<OperationDef> */
    private static function definitions(): array
    {
        return [
            new OperationDef('qc.save_results', 'qc', 'enter QC test results',
                'write.qc.save_results', SaveTestResults::class, ['TestsDataSet.SaveChanges']),
            new OperationDef('qc.approve_sublot', 'qc', 'approve (release) a tested sublot',
                'write.qc.approve_sublot', ApproveSublot::class, ['ReleaseDataSet.Approve']),
            new OperationDef('qc.reject_sublot', 'qc', 'reject a tested sublot',
                'write.qc.reject_sublot', RejectSublot::class, ['ReleaseDataSet.Reject']),
            new OperationDef('sales.create_order', 'sales_orders', 'create a sales order or quote',
                'write.sales.create_order', CreateOrder::class, ['ShippingOrderDataSet.SaveChanges']),
            new OperationDef('sales.edit_order', 'sales_orders', 'change an open sales order',
                'write.sales.edit_order', EditOrder::class, ['ShippingOrderDataSet.SaveChanges']),
            new OperationDef('sales.release_order', 'sales_orders', 'release a sales order from credit hold',
                'write.sales.release_order', ReleaseOrder::class, ['ShippingOrderDataSet.SaveChanges']),
            new OperationDef('sales.complete_order', 'sales_orders', 'complete (close) a sales order',
                'write.sales.complete_order', CompleteOrder::class, ['ShippingOrderDataSet.SaveChanges']),
            new OperationDef('sales.complete_sale', 'sales_orders', 'ship and invoice a sales order without a waybill',
                'write.sales.complete_sale', CompleteSale::class, ['ShippingOrderDataSet.CompleteSale']),
            new OperationDef('po.create_order', 'purchase_orders', 'create a purchase order',
                'write.po.create_order', CreatePurchaseOrder::class, ['PurchaseOrderDataSet.SaveChanges']),
            new OperationDef('po.edit_order', 'purchase_orders', 'change an open purchase order',
                'write.po.edit_order', EditPurchaseOrder::class, ['PurchaseOrderDataSet.SaveChanges']),
            new OperationDef('po.complete_order', 'purchase_orders', 'complete (close) a purchase order',
                'write.po.complete_order', CompletePurchaseOrder::class, ['PurchaseOrderDataSet.SaveChanges']),
            new OperationDef('po.receive', 'purchase_orders', 'receive a purchase order line',
                'write.po.receive', ReceiveLine::class, ['PurchaseReceiptDataSet.SaveChanges']),
            new OperationDef('po.reverse_receipt', 'purchase_orders', 'reverse a purchase receipt',
                'write.po.reverse_receipt', ReverseReceipt::class, ['PurchaseReceiptDataSet.ReverseReceipt']),
        ];
    }
}
