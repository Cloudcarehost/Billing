<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    use EnumValues;

    case OrderDeduction = 'order_deduction';
    case OrderReversal = 'order_reversal';
    case Wastage = 'wastage';
    case StockReceipt = 'stock_receipt';
    case ManualAdjustment = 'manual_adjustment';
    case OpeningStock = 'opening_stock';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case StockCountAdjustment = 'stock_count_adjustment';
}
