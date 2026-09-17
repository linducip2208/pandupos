<?php

namespace Tests\Unit;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliatePayout;
use App\Models\AffiliateReferral;
use App\Models\AnnouncementDelivery;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceItem;
use App\Models\BillingTransaction;
use App\Models\BlogPost;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Contact;
use App\Models\CouponRedemption;
use App\Models\CustomerLogin;
use App\Models\Device;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\IdempotencyKey;
use App\Models\ImpersonationSession;
use App\Models\IntegrationFeatureAssignment;
use App\Models\IntegrationProvider;
use App\Models\InventoryBalance;
use App\Models\InventoryBatch;
use App\Models\Membership;
use App\Models\PaymentProof;
use App\Models\PlanEntitlement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Register;
use App\Models\SalePayment;
use App\Models\SalesInvoice;
use App\Models\SalesLine;
use App\Models\SalesReturn;
use App\Models\SerialNumber;
use App\Models\ServerChangeLog;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentLine;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\StockReservation;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SystemSetting;
use App\Models\TenantDomain;
use App\Models\TenantModule;
use App\Models\TenantSetting;
use App\Models\TransferLine;
use App\Models\TransferOrder;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Relations\Relation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DatabaseRelationshipAuditTest extends TestCase
{
    #[DataProvider('foreignKeyRelationships')]
    public function test_every_domain_foreign_key_has_an_eloquent_relationship(string $modelClass, string $method): void
    {
        $model = new $modelClass;

        $this->assertTrue($model->usesTimestamps(), "{$modelClass} must stay consistent with migration timestamps.");
        $this->assertInstanceOf(Relation::class, $model->{$method}(), "{$modelClass}::{$method} must be an Eloquent relationship.");
    }

    public static function foreignKeyRelationships(): array
    {
        $map = [
            Branch::class => ['tenant'], Warehouse::class => ['tenant', 'branch'], WarehouseLocation::class => ['tenant', 'warehouse'],
            Register::class => ['tenant', 'branch'], Membership::class => ['tenant', 'user'],
            TenantModule::class => ['tenant', 'module'], PlanEntitlement::class => ['plan'],
            Subscription::class => ['tenant', 'plan'], SubscriptionEvent::class => ['tenant', 'subscription', 'actor'],
            AuditLog::class => ['tenant', 'actor'], IdempotencyKey::class => ['tenant'],
            Affiliate::class => ['user'], AffiliateReferral::class => ['affiliateUser', 'referredTenant'],
            AffiliateCommission::class => ['affiliateUser', 'referredTenant', 'subscription'], AffiliatePayout::class => ['affiliateUser', 'creator'],
            CouponRedemption::class => ['coupon', 'tenant', 'subscription'], BillingInvoice::class => ['tenant', 'subscription'],
            BillingTransaction::class => ['tenant', 'invoice'], AnnouncementDelivery::class => ['announcement', 'tenant'],
            Category::class => ['tenant', 'parent'], Brand::class => ['tenant'], Unit::class => ['tenant'],
            Product::class => ['tenant', 'category', 'brand', 'unit'], ProductVariant::class => ['tenant', 'product'],
            InventoryBatch::class => ['tenant', 'variant', 'warehouse', 'supplier', 'purchase'],
            InventoryBalance::class => ['tenant', 'warehouse', 'variant'],
            SerialNumber::class => ['tenant', 'variant', 'warehouse', 'inventoryBatch', 'purchase', 'salesInvoice'],
            StockReservation::class => ['tenant', 'warehouse', 'warehouseLocation', 'variant', 'inventoryBatch'],
            StockAdjustment::class => ['tenant', 'warehouse', 'requester', 'approver', 'poster'],
            StockAdjustmentLine::class => ['adjustment', 'variant'],
            StockCount::class => ['tenant', 'warehouse', 'creator', 'approver', 'poster'],
            StockCountLine::class => ['stockCount', 'variant'],
            StockMovement::class => ['tenant', 'warehouse', 'warehouseLocation', 'variant', 'inventoryBatch', 'serialNumber'],
            TransferOrder::class => ['tenant', 'fromWarehouse', 'toWarehouse', 'approver', 'shipper'],
            TransferLine::class => ['transferOrder', 'variant'], Contact::class => ['tenant'],
            Purchase::class => ['tenant', 'warehouse', 'contact'], PurchaseLine::class => ['purchase', 'variant'],
            GoodsReceipt::class => ['tenant', 'purchase', 'warehouse', 'receiver'],
            GoodsReceiptLine::class => ['goodsReceipt', 'purchaseLine', 'variant'],
            SupplierInvoice::class => ['tenant', 'purchase', 'supplier'],
            SupplierPayment::class => ['tenant', 'invoice', 'creator'],
            PurchaseReturn::class => ['tenant', 'purchase', 'creator'],
            PurchaseReturnLine::class => ['purchaseReturn', 'purchaseLine', 'variant'],
            CashSession::class => ['tenant', 'register', 'openedBy'], SalesInvoice::class => ['tenant', 'branch', 'warehouse', 'contact'],
            SalesLine::class => ['invoice', 'variant'], SalePayment::class => ['tenant', 'invoice'],
            SalesReturn::class => ['tenant', 'invoice'], Device::class => ['tenant'],
            ServerChangeLog::class => ['tenant'], WebhookEndpoint::class => ['tenant'],
            WebhookDelivery::class => ['endpoint'], TenantSetting::class => ['tenant'],
            TenantDomain::class => ['tenant'], ImpersonationSession::class => ['platformUser', 'tenant', 'targetUser'],
            BillingInvoiceItem::class => ['invoice'], BlogPost::class => ['category', 'author'],
            IntegrationProvider::class => ['tenant'], SystemSetting::class => ['tenant'],
            IntegrationFeatureAssignment::class => ['tenant', 'provider'],
            ApprovalRequest::class => ['tenant', 'requestedBy', 'decidedBy'], CustomerLogin::class => ['tenant', 'contact'],
            PaymentProof::class => ['tenant', 'customerLogin', 'invoice'],
        ];
        $cases = [];
        foreach ($map as $model => $methods) {
            foreach ($methods as $method) {
                $cases[$model.'::'.$method] = [$model, $method];
            }
        }

        return $cases;
    }
}
