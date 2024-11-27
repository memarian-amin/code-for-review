<?php

namespace App\Models\Api\v1;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class OrdersList extends Model
{
    use HasFactory;

    const MARKET_TYPE = '1';
    const LIMIT_TYPE = '2';

    const BUY_ROLE = '1';
    const SELL_ROLE = '2';

    const CANCEL_STATUS = '-1';
    const PENDING_STATUS = '0';
    const DONE_STATUS = '1';
    const PART_DEAL = '2';

    const IRR_PAYMENT = 'IRR';
    const USDT_PAYMENT = 'USDT';

    const IRT = 'IRT';
    const USDT = 'USDT';

    const REAL = 'real';
    const VIRTUAL = 'virtual';

    const FILLED = 100;

    const BTC = 'BTCUSDT';
    const ETH = 'ETHUSDT';

    protected $guarded = [];

    protected $table = 'orders_list';

    public function doneOrder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return self::belongsTo(DoneOrdersList::class, 'id', 'order_id');
    }

    public static function findById($order_id)
    {
        return self::query()->find($order_id);
    }

    public static function cancelOrder($order_id)
    {
        $order = self::query()->find($order_id);
        $order->status = self::CANCEL_STATUS;
        $order->save();
    }

    public static function getOrderListWithoutFilter($skip = null, $number_of_records, $user_id)
    {
        if($skip)
        {
            return OrdersList::query()->where('user_id', $user_id)
                ->skip($skip)->take($number_of_records)
                ->orderBy('id','desc')
//                ->where('status', '!=', '-1')
                ->orderBy('id', 'desc')->get();
        }else{
            return OrdersList::query()->where('user_id', $user_id)
                ->take($number_of_records)
                ->orderBy('id','desc')
//                ->where('status', '!=', '-1')
                ->orderBy('id', 'desc')->get();
        }
    }

    public static function getCountOfPages($user_id, $number_of_records)
    {
        $count = OrdersList::query()->where('user_id', $user_id)->count();
        return round($count / $number_of_records);
    }

    public static function countOfPagesByDate($pair = null, $type = null, $role = null, $from_date , $to_date, $user_id, $number_of_records)
    {
        if ($pair && $type && $role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('market', $pair)
                ->where('type', $type)
                ->where('role', $role)
                ->whereDate('created_at', '>=', $from_date)
                ->whereDate('created_at', '<=', $to_date)
                ->count();
        }

        if (!$pair && !$type && !$role) {
            $count = self::query()->where('user_id', $user_id)
                ->whereDate('created_at', '>=', $from_date)
                ->whereDate('created_at', '<=', $to_date)
                ->count();
        }

        if ($pair && !$type && !$role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('market', $pair)
                ->whereDate('created_at', '>=', $from_date)
                ->whereDate('created_at', '<=', $to_date)
                ->count();
        }

        if ($pair && $type && !$role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('market', $pair)
                ->where('type', $type)
                ->whereDate('created_at', '>=', $from_date)
                ->whereDate('created_at', '<=', $to_date)
                ->count();
        }

        if ($pair && !$type && $role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('market', $pair)
                ->where('role', $role)
                ->whereDate('created_at', '>=', $from_date)
                ->whereDate('created_at', '<=', $to_date)
                ->count();
        }

        if (!$pair && $type && $role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('type', $type)
                ->where('role', $role)
                ->whereDate('created_at', '>=', $from_date)
                ->whereDate('created_at', '<=', $to_date)
                ->count();
        }

        if (!$pair && $type && !$role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('type', $type)
                ->whereDate('created_at', '>=', $from_date)
                ->whereDate('created_at', '<=', $to_date)
                ->count();
        }

        if (!$pair && !$type && $role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('role', $role)
                ->whereDate('created_at', '>=', $from_date)
                ->whereDate('created_at', '<=', $to_date)
                ->count();
        }

        return round($count / $number_of_records);
    }

    public static function countOfPagesWithoutDate($pair = null, $type = null, $role = null, $user_id, $number_of_records)
    {
        if ($pair && $type && $role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('market', $pair)
                ->where('type', $type)
                ->where('role', $role)
                ->count();
        }

        if (!$pair && !$type && !$role) {
            $count = self::query()->where('user_id', $user_id)->count();
        }

        if ($pair && !$type && !$role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('market', $pair)->count();
        }

        if ($pair && $type && !$role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('market', $pair)
                ->where('type', $type)
                ->count();
        }

        if ($pair && !$type && $role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('market', $pair)
                ->where('role', $role)
                ->count();
        }

        if (!$pair && $type && $role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('type', $type)
                ->where('role', $role)
                ->count();
        }

        if (!$pair && $type && !$role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('type', $type)
                ->count();
        }

        if (!$pair && !$type && $role) {
            $count = self::query()->where('user_id', $user_id)
                ->where('role', $role)
                ->count();
        }

        return round($count / $number_of_records);
    }

    public static function updateOrders($order_id)
    {
        $order = self::query()->find($order_id);
        $order->filled = 100;
        $order->status = self::DONE_STATUS;
        $order->save();
    }
    /**
     * Update the limit order with amount that has completed and set its status to part_deal
     */
/**
 * Update limit orders with new filled percentage and status.
 *
 * @param int $order_id The ID of the order.
 * @param string $status The new status for the order.
 * @param float|null $filled_percentage The filled percentage to be added (optional).
 *
 * @return \App\Models\OrdersList|null The updated order or null if the order is not found.
 */
public static function updateLimitOrders($order_id, $status, $change_filled_percentage = null)
{
    try {
        // Find the order by ID
        $order = self::find($order_id);

        if (!$order) {
            return null;
        }

        // Calculate new filled quantity
        $old_filled = $order->filled;
        $new_filled = $old_filled + ($change_filled_percentage ?? 0);

        // Ensure new filled quantity does not exceed the maximum allowed
        $new_filled = min($new_filled, OrdersList::FILLED);

        // Update order details
        $order->filled = $new_filled;
        $order->status = $status;
        $order->save();

        return $order;
    } catch (\Exception $e) {
        // Handle the exception, log it, or rethrow if necessary
        return null;
    }
}


    public static function searchByDate($skip = null, $number_of_records, $pair = null, $type = null, $role = null, $from_date , $to_date, $user_id )
    {
        if ($skip) {

            if ($pair && $type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('market', $pair)
                    ->where('type', $type)
                    ->where('role', $role)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && !$type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if ($pair && !$type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('market', $pair)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if ($pair && $type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('market', $pair)
                    ->where('type', $type)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if ($pair && !$type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('market', $pair)
                    ->where('role', $role)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && $type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('type', $type)
                    ->where('role', $role)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && $type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('type', $type)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && !$type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('role', $role)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

        } else {

            if ($pair && $type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('market', $pair)
                    ->where('type', $type)
                    ->where('role', $role)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && !$type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if ($pair && !$type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('market', $pair)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if ($pair && $type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('market', $pair)
                    ->where('type', $type)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if ($pair && !$type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('market', $pair)
                    ->where('role', $role)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && $type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('type', $type)
                    ->where('role', $role)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && $type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('type', $type)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && !$type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('role', $role)
                    ->whereDate('created_at', '>=', $from_date)
                    ->whereDate('created_at', '<=', $to_date)
                    ->orderBy('id', 'desc')->get();
            }
        }

    }

    public static function searchWithoutDate($skip, $number_of_records, $pair = null, $type = null, $role = null, $user_id)
    {
        if ($skip) {

            if ($pair && $type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('market', $pair)
                    ->where('type', $type)
                    ->where('role', $role)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && !$type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)->orderBy('id', 'desc')->get();
            }

            if ($pair && !$type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('market', $pair)->orderBy('id', 'desc')->get();
            }

            if ($pair && $type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('market', $pair)
                    ->where('type', $type)
                    ->orderBy('id', 'desc')->get();
            }

            if ($pair && !$type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('market', $pair)
                    ->where('role', $role)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && $type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('type', $type)
                    ->where('role', $role)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && $type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('type', $type)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && !$type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->skip($skip)->take($number_of_records)
                    ->where('role', $role)
                    ->orderBy('id', 'desc')->get();
            }

        } else {

            if ($pair && $type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('market', $pair)
                    ->where('type', $type)
                    ->where('role', $role)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && !$type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)->orderBy('id', 'desc')->get();
            }

            if ($pair && !$type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('market', $pair)->orderBy('id', 'desc')->get();
            }

            if ($pair && $type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('market', $pair)
                    ->where('type', $type)
                    ->orderBy('id', 'desc')->get();
            }

            if ($pair && !$type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('market', $pair)
                    ->where('role', $role)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && $type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('type', $type)
                    ->where('role', $role)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && $type && !$role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('type', $type)
                    ->orderBy('id', 'desc')->get();
            }

            if (!$pair && !$type && $role) {
                return self::query()->where('user_id', $user_id)
                    ->take($number_of_records)
                    ->where('role', $role)
                    ->orderBy('id', 'desc')->get();
            }

        }

    }

    public static function findByMarket($market)
    {
        return self::query()->where('market', $market)->get();
    }

    public static function findByMarketBuy($market, $from_date = null, $to_date = null, $user_id)
    {
        if (isset($from_date) && isset($to_date)) {

            return self::query()->where('status', '1')->where('user_id', $user_id)->where('market' , 'like',  $market.'%')->whereDate('created_at', '>=', $from_date)->whereDate('created_at', '<=', $to_date)->get();
        } else
            return self::query()->where('status', '1')->where('user_id', $user_id)->where('market' , 'like',  $market.'%')->get();
    }

    public static function getWithUserID($user_id)
    {
        return self::query()->where('user_id', $user_id)->get();
    }

    // returns all buy or sell pending orders (both pending and partial complete limit orders)
    public static function getPendingOrders($user_id)
    {
        return self::query()
            ->where('user_id', $user_id)
            ->where('type', self::LIMIT_TYPE)
            ->whereIn('status', [self::PENDING_STATUS, self::PART_DEAL])
            ->get();
    }

    
}

