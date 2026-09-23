<?php

namespace App\Support;

class PermissionCatalog
{
    /** @return list<array{group: string, code: string, name: string}> */
    public static function definitions(): array
    {
        return [
            ['group' => 'dashboard', 'code' => 'dashboard.view', 'name' => 'View dashboard'],
            ['group' => 'tables', 'code' => 'tables.view', 'name' => 'View table status'],
            ['group' => 'tables', 'code' => 'tables.configure', 'name' => 'Create and configure tables'],
            ['group' => 'tables', 'code' => 'sessions.open', 'name' => 'Open dining sessions'],
            ['group' => 'tables', 'code' => 'sessions.assign', 'name' => 'Assign sessions to another waiter'],
            ['group' => 'orders', 'code' => 'orders.view', 'name' => 'View table orders'],
            ['group' => 'orders', 'code' => 'orders.create', 'name' => 'Take and send orders'],
            ['group' => 'orders', 'code' => 'orders.manage', 'name' => 'Modify and cancel orders'],
            ['group' => 'orders', 'code' => 'orders.cancel', 'name' => 'Cancel pending order items'],
            ['group' => 'orders', 'code' => 'orders.cancel_prepared', 'name' => 'Cancel preparing or ready items'],
            ['group' => 'orders', 'code' => 'orders.cancel_served', 'name' => 'Cancel served items'],
            ['group' => 'orders', 'code' => 'orders.cancel_high_value', 'name' => 'Approve high-value cancellations'],
            ['group' => 'kitchen', 'code' => 'kitchen.view', 'name' => 'View kitchen orders'],
            ['group' => 'kitchen', 'code' => 'kitchen.update', 'name' => 'Update preparation status'],
            ['group' => 'billing', 'code' => 'billing.view', 'name' => 'View bills'],
            ['group' => 'billing', 'code' => 'billing.request', 'name' => 'Request a table bill'],
            ['group' => 'billing', 'code' => 'billing.create', 'name' => 'Create and complete bills'],
            ['group' => 'billing', 'code' => 'billing.close_without_sale', 'name' => 'Close zero-value sessions without sale'],
            ['group' => 'billing', 'code' => 'billing.discount', 'name' => 'Apply bill discounts'],
            ['group' => 'billing', 'code' => 'billing.void', 'name' => 'Void bills'],
            ['group' => 'billing', 'code' => 'billing.refund', 'name' => 'Refund bills'],
            ['group' => 'payments', 'code' => 'payments.manage', 'name' => 'Record and manage payments'],
            ['group' => 'catalog', 'code' => 'catalog.view', 'name' => 'View products and categories'],
            ['group' => 'catalog', 'code' => 'catalog.manage', 'name' => 'Manage products and categories'],
            ['group' => 'inventory', 'code' => 'inventory.view', 'name' => 'View inventory'],
            ['group' => 'inventory', 'code' => 'inventory.receive', 'name' => 'Receive stock'],
            ['group' => 'inventory', 'code' => 'inventory.adjust', 'name' => 'Adjust stock'],
            ['group' => 'customers', 'code' => 'customers.view', 'name' => 'View customers'],
            ['group' => 'customers', 'code' => 'customers.manage', 'name' => 'Manage customers'],
            ['group' => 'reports', 'code' => 'reports.view', 'name' => 'View reports'],
            ['group' => 'reports', 'code' => 'reports.export', 'name' => 'Export reports'],
            ['group' => 'finance', 'code' => 'finance.view', 'name' => 'View money'],
            ['group' => 'finance', 'code' => 'finance.manage', 'name' => 'Manage money entries'],
            ['group' => 'users', 'code' => 'users.view', 'name' => 'View users and roles'],
            ['group' => 'users', 'code' => 'users.manage', 'name' => 'Manage users and roles'],
            ['group' => 'settings', 'code' => 'settings.manage', 'name' => 'Manage hotel settings'],
            ['group' => 'outlets', 'code' => 'outlets.all', 'name' => 'Access every outlet'],
        ];
    }
}
