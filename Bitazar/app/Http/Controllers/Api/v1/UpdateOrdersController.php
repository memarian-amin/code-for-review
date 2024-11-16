<?php

namespace App\Http\Controllers\Api\v1;

use App\Jobs\SmsJob;
use App\Models\User;
use App\Jobs\BuyOrder;
use App\Helpers\CoinexApi;
use App\Helpers\SmsHelper;
use App\Models\Api\v1\Logs;
use Illuminate\Http\Request;
use App\Events\pay_commision;
use App\Helpers\FreezeHelper;
use App\Jobs\UpdateOrdersJob;
use App\Helpers\RequestHelper;
use App\Services\CoinexService;
use App\Jobs\ProcessUpdateOrder;
use App\Models\Api\v1\UsdtPrice;
use Elegant\Sanitizer\Sanitizer;
use App\Models\Api\v1\OrdersList;
use App\Models\Api\v1\RequestType;
use Illuminate\Support\Facades\DB;
use App\Helpers\NotificationHelper;
use App\Models\Api\v1\ExchangeList;
use Lin\Coinex\Api\Perpetual\Order;
use App\Http\Controllers\Controller;
use App\Models\Api\v1\DoneOrdersList;
use App\Models\Api\v1\UpdateOrderLog;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;
use App\Jobs\UserMessageNotificationJob;
use App\Models\Api\v1\MongoUpdatesError;
use Illuminate\Support\Facades\Response;
use App\Repositories\Api\v1\OrderRepository;
use Illuminate\Support\Facades\Notification;
use App\Notifications\UserMessagesNotification;
use App\Repositories\Api\v1\UserWalletsRepository;
use App\Repositories\Api\v1\NotificationRepository;

class UpdateOrdersController extends Controller
{

    private const CANCEL_DEAL = '-1';
    private const NO_DEAL = '0';
    private const DONE_DEAL = '1';
    private const PART_DEAL = '2';

    private const STR_CANCEL_DEAL = 'cancel';
    private const STR_NO_DEAL = 'not_deal';
    private const STR_DONE_DEAL = 'done';
    private const STR_PART_DEAL = 'part_deal';

    private $userWalletsRepository;
    private $orderRepository;
    private $notificationRepository;
    private $notificationHelper;
    private $coinexService;
    private $tether_price;
    private $user = null;
    private $done_order;
    private $order;
    private $info;

    private $order_price;
    private $order_price_type;
    private $total_price;
    private $total_price_type;
    /**
     * @var NotificationRepository
     */
    public function __construct()
    {
        $notificationService = new NotificationService;
        $this->userWalletsRepository = new UserWalletsRepository;
        $this->notificationHelper = new NotificationHelper($notificationService);
        $this->coinexService = new CoinexService;
        $this->orderRepository = new OrderRepository;
        $this->notificationRepository = new NotificationRepository;
    }

    /**
     * @group Update order section
     * Api to update order from node js data
     * @json  {
     *            "method": "order.update",
     *            "params" :    [
     *                2,
     *                [
     *                {
     *                    "asset_fee": "0",
     *                    "account": 1,
     *                    "option": 2,
     *                   "money_fee": "0",
     *                    "stock_fee": "0",
     *                    "ctime": 1558680350.41878,
     *                    "maker_fee": "0.0001",
     *                    "price": "0.01900000",
     *                    "deal_stock": "0",
     *                    "fee_discount": "0.9000",
     *                    "side": 1,
     *                    "source": "api",
     *                    "amount": "1.00000000",
     *                    "user": 553,
     *                    "mtime": 1558680350.41878,
     *                    "fee_asset": "CET",
     *                    "last_deal_amount": "52.22635926",
     *                    "last_deal_price": "0",
     *                    "last_deal_time": "0",
     *                    "last_deal_id": "0",
     *                    "last_role": 2,
     *                    "deal_money": "0",
     *                    "left": "1.00000000",
     *                    "type": 1,
     *                    "id": 91256852791,
     *                    "market": "BTCUSDT",
     *                    "taker_fee": "0.0001",
     *                    "client_id": "test_123",
     *                    "stop_id": 0
     *                }
     *            ]
     *        ]
     *     }
     **/
    public function store(Request $request)
    {

        // Get json from request and store that
        $new_updates = $request->getContent();
        $update_order_id = UpdateOrderLog::store(json_encode($new_updates));
        $new_updates = json_decode($new_updates, true);

        $this->tether_price = UsdtPrice::get()->quantity / 10;
        DB::beginTransaction();

         try {

             // Flag to detect successful operation
             $success = false;

             for ($i = 1; $i < count($new_updates['params']); $i++) {
                 //if it is limit order  -- 1: limit order, 2: market order
                 if ($new_updates['params'][$i]['type'] == '1') {

                     // Prepare new done order to update
                     $this->done_order = [
                         'identifier' => @$new_updates['params'][$i]['id'],
                         'type' => @$new_updates['params'][$i]['type'],
                         'side' => @$new_updates['params'][$i]['side'],
                         'user' => @$new_updates['params'][$i]['user'],
                         'account' => @$new_updates['params'][$i]['account'],
                         'option' => @$new_updates['params'][$i]['option'],
                         'amount' => @$new_updates['params'][$i]['amount'],
                         'create_time' => @$new_updates['params'][$i]['ctime'],
                         'finished_time' => @$new_updates['params'][$i]['mtime'],
                         'market' => @$new_updates['params'][$i]['market'],
                         'source' => @$new_updates['params'][$i]['source'],
                         'price' => @$new_updates['params'][$i]['price'],
                         'client_id' => @$new_updates['params'][$i]['client_id'],
                         'taker_fee_rate' => @$new_updates['params'][$i]['taker_fee'],
                         'maker_fee_rate' => @$new_updates['params'][$i]['maker_fee'],
                         'left' => @$new_updates['params'][$i]['left'],
                         'deal_stock' => @$new_updates['params'][$i]['deal_stock'],
                         'deal_money' => @$new_updates['params'][$i]['deal_money'],
                         'money_fee' => @$new_updates['params'][$i]['money_fee'],
                         'stock_fee' => @$new_updates['params'][$i]['stock_fee'],
                         'asset_fee' => @$new_updates['params'][$i]['asset_fee'],

                         'deal_fee' => @$new_updates['params'][$i]['stock_fee'],
                         'fee_discount' => @$new_updates['params'][$i]['fee_discount'],
                         'deal_amount' => @$new_updates['params'][$i]['last_deal_amount'],
                         'last_deal_price' => @$new_updates['params'][$i]['last_deal_price'],
                         'last_deal_time' => @$new_updates['params'][$i]['last_deal_time'],
                         'last_deal_id' => @$new_updates['params'][$i]['last_deal_id'],
                         'last_role' => @$new_updates['params'][$i]['last_role'],
                         'stop_id' => @$new_updates['params'][$i]['stop_id'],
                         'fee_asset' => @$new_updates['params'][$i]['fee_asset'],
                     ];

                     // Check status of order in coinex
                     $params = [
                         'id' => $new_updates['params'][$i]['id'],
                         'market' => $new_updates['params'][$i]['market']
                     ];
                     $result = CoinexApi::send('order/status', $params, 'get');
                     // Try 3 times
                     $j = 0;
                     while (@$result->code != 0) {

                         if ($j < 3)
                             break;

                         $result = CoinexApi::send('order/status', $params, 'get');
                         if (@$result->code == 0)
                             break;

                         $j++;
                     }
                     $this->info = (object) $result->data;

                     if ($this->info) {

                         $this->done_order = $this->defineOrderStatus();

                         $maxRetries = 3; // Number of maximum retries to find order
                         $retryInterval = 4; // Time to wait between retries in seconds
                         $order_id = null;

                         for ($retry = 1; $retry <= $maxRetries; $retry++) {
                             $order_id = DoneOrdersList::updateDoneOrders($this->done_order);

                             if ($order_id !== false) {
                                 // Successfully updated, break out of the loop
                                 break;
                             }

                             // Wait for the specified interval before retrying
                             sleep($retryInterval);
                         }

                         // If $order_id is still false after retries, throw an exception
                         if ($order_id === null || $order_id === false) {
                             throw new \Exception('Seems orders don\'t exist after retries.');
                         }

                         if ($order_id) {
                             //update done order and find corresponding order  using "identifier" property of json from coinex
                             $this->order = OrdersList::findById($order_id);
                             //fill $this->user property
                             $this->fillUserVariable($this->order->user_id);

                             //For test - remove for real environment
                             //$this->info->status = "part_deal";
                             //$this->order->status = OrdersList::PENDING_STATUS;

                             //only process it if order status is pending
                             /*if (isset($this->order->status) && ($this->order->status == OrdersList::PART_DEAL || $this->order->status == OrdersList::PENDING_STATUS || $this->order->status == OrdersList::CANCEL_STATUS ))
                             {*/


                             // Cache::forget('wallet_list_' . $this->user->id);

                             // load order info
                             $temp_done_order = DoneOrdersList::findByID($new_updates['params'][$i]['id']);
                             $temp_order = OrdersList::findById($temp_done_order->order_id);

                             $last_deal_amount = $new_updates['params'][$i]['last_deal_amount'];
                             $last_deal_price = $new_updates['params'][$i]['last_deal_price'];
                             $last_deal_value_usdt = $last_deal_amount * $temp_order->currency_price;
                             $last_deal_value_irt =  $last_deal_value_usdt * $temp_order->tether_price;
                             $wallet_name = str_replace('USDT', '', $new_updates['params'][$i]['market']);


                             $real_filled_qty = OrderRepository::increaseFilledQty($temp_order->id, $last_deal_amount);
                             $real_filled_usdt = OrderRepository::increaseFilledUsdt($temp_order->id, $last_deal_value_usdt);
                             $real_filled_irt = OrderRepository::increaseFilledIrt($temp_order->id, $last_deal_value_irt);
                             $max_qty_user = $temp_order->max_qty_user;
                             $irr_or_usdt = $temp_order->payment_type; //usdt or irt

                             $buy_or_sell = $temp_order->role;
                             if ($buy_or_sell == OrdersList::BUY_ROLE)
                                 $irr_or_usdt = $temp_order->payment_type;
                             else
                                 $irr_or_usdt = $temp_order->received_type;


                             switch ($this->info->status) {

                                 case 'not_deal':
                                     break;

                                 case 'part_deal':

                                     switch ($temp_order->role) {

                                         case OrdersList::BUY_ROLE: /* buy */

                                             UserWalletsRepository::handleWalletUpdatOnLimitUpdate($this->user->id, $buy_or_sell, $irr_or_usdt, $wallet_name, $real_filled_qty, $real_filled_irt, $real_filled_usdt, $temp_order->id, true);
                                             break;

                                         case OrdersList::SELL_ROLE: /* sell */

                                             UserWalletsRepository::handleWalletUpdatOnLimitUpdate($this->user->id, $buy_or_sell, $irr_or_usdt, $wallet_name, $real_filled_qty, $real_filled_irt, $real_filled_usdt, $temp_order->id, true);
                                             break;
                                     }

                                     //set wage of this order is deducted
                                     if ($temp_order->is_wage_reducted == 0)
                                         OrderRepository::setWageReducted($temp_order->user_id);

                                     //calculate percentage of order completed till now
                                     $percentage_done = $this->CalculatePercentageDone($max_qty_user, $real_filled_qty);
                                     // Update order list to pending PART_DEAL
                                     $this->order = OrdersList::updateLimitOrders($order_id, OrdersList::PART_DEAL, $percentage_done);

                                     // Notification with api
                                     $this->sendSuccessfulOrderNotification();

                                     break;

                                 case 'done':

                                     switch ($temp_order->role) {

                                         case OrdersList::BUY_ROLE: /* buy */
                                             $title = 'update_buy_order_title';

                                             UserWalletsRepository::handleWalletUpdatOnLimitUpdate($this->user->id, $buy_or_sell, $irr_or_usdt, $wallet_name, $real_filled_qty, $real_filled_irt, $real_filled_usdt, $temp_order->id, true);


                                             $this->userWalletsRepository->freeRemainingFreezeOfOrder($temp_order->id, true);

                                             break;

                                         case OrdersList::SELL_ROLE: /* sell */
                                             $title = 'update_sell_order_title';

                                             UserWalletsRepository::handleWalletUpdatOnLimitUpdate($this->user->id, $buy_or_sell, $irr_or_usdt, $wallet_name, $real_filled_qty, $real_filled_irt, $real_filled_usdt, $temp_order->id, true);
                                             $this->userWalletsRepository->freeRemainingFreezeOfOrder($temp_order->id, true);

                                             break;
                                     }

                                     // Send notification to user

                                     if ($this->order->role == OrdersList::BUY_ROLE) {

                                         unset($message, $title);
                                         $message = 'update_order_completed_content' . ':' . $order_id;
                                         $title = 'update_buy_order_title' . ':' . $this->order->market . ':' . number_format($this->order->amount, 2);
                                         // Notification::send($this->user, new UserMessagesNotification($this->user, $message, $title));
                                         UserMessageNotificationJob::dispatch($this->user, $message, $title);

                                         $message = NotificationRepository::prepareNotificationParameter($message);
                                         $title = NotificationRepository::prepareNotificationParameterTitle($title);

                                         // $this->sendSuccessfulNotification($title, $message);
                                         NotificationRepository::sendSuccessfulNotification($this->order->user_id, $title, $message);
                                     } elseif ($this->order->role == OrdersList::SELL_ROLE) {

                                         unset($message, $title);
                                         $message = 'update_order_completed_content' . ':' . $order_id;
                                         $title = 'update_sell_order_title' . ':' . $this->order->market . ':' . number_format($this->order->amount, 2);
                                         // Notification::send($this->user, new UserMessagesNotification($this->user, $message, $title));
                                         UserMessageNotificationJob::dispatch($this->user, $message, $title);

                                         $message = NotificationRepository::prepareNotificationParameter($message);
                                         $title = NotificationRepository::prepareNotificationParameterTitle($title);
                                         // $this->sendSuccessfulNotification($title, $message);
                                         NotificationRepository::sendSuccessfulNotification($this->order->user_id, $title, $message);
                                     }

                                     // SmsHelper::sendMessage($this->user->mobile, $this->templates_id['limit_order_complete'], [$new_updates['params'][$i]['market'], $order_id]);

                                     SmsJob::dispatch($this->user->mobile, $this->templates_id['limit_order_complete'], [$new_updates['params'][$i]['market'], $order_id]);

                                     // Call event to give commissions
                                     $exchange_symbol = $wallet_name;
                                     $currency = ExchangeList::findBySymbol($exchange_symbol);
                                     event(new pay_commision($this->user, $this->order->current_wage * $this->order->tether_price, $currency->id));

                                     //set wage of this order is deducted
                                     if ($temp_order->is_wage_reducted == 0)
                                         OrderRepository::setWageReducted($temp_order->user_id);

                                     //set amount perentage to 100
                                     $percentage_done = 100;
                                     // Update order list to Done
                                     $this->order = OrdersList::updateLimitOrders($order_id, OrdersList::DONE_STATUS, $percentage_done);

                                     // Notification with api
                                     $this->sendSuccessfulOrderNotification();

                                     break;

                                 case 'cancel':
                                     //only process it if it is not already processed previously
                                     if ($temp_order->staus !== OrdersList::CANCEL_STATUS) {
                                         //we calculate how much we need to give back to user
                                         $remaining_order_qty_user = $temp_order->max_qty_user - $temp_order->filled_qty;
                                         $remaining_order_usdt_user = $temp_order->max_usdt_user - $temp_order->filled_usdt;
                                         $remaining_order_irt_user = $temp_order->max_irt_user - $temp_order->filled_irt;

                                         //calculate how much we can deduct for the wages for our site
                                         $remaining_order_qty_site = $temp_order->amount - $temp_order->max_qty_user;
                                         $remaining_order_usdt_site = $temp_order->total_usdt_price - $temp_order->max_usdt_user;
                                         $remaining_order_irt_site = $temp_order->total_irt_price - $temp_order->max_irt_user;

                                         // if nothing is not processed yet we give all money back to user
                                         $give_all_back = ($remaining_order_qty_user == $max_qty_user);

                                         switch ($temp_order->role) {
                                             case OrdersList::BUY_ROLE: /* buy */
                                                 $title = 'update_buy_order_title';
                                                 if (@$temp_order->payment_type && $temp_order->payment_type == 'IRR') {

                                                     if ($give_all_back) {
                                                         $reduced_wallet = $this->userWalletsRepository
                                                             ->unfreezeAmount($this->user->id, 'IRR', $temp_order->total_irt_price);
                                                     } else {
                                                         $reduced_wallet = $this->userWalletsRepository
                                                             ->unfreezeAmount($this->user->id, 'IRR', $remaining_order_irt_user);

                                                         $this->userWalletsRepository
                                                             ->decreaseFreezeAmount($this->user->id, 'IRR', $remaining_order_irt_site);
                                                     }
                                                 } else {

                                                     if ($give_all_back) {
                                                         $reduced_wallet = $this->userWalletsRepository
                                                             ->unfreezeAmount($this->user->id, 'USDT', $temp_order->total_usdt_price);
                                                     } else {
                                                         $reduced_wallet = $this->userWalletsRepository
                                                             ->unfreezeAmount($this->user->id, 'USDT', $remaining_order_usdt_user);

                                                         $this->userWalletsRepository
                                                             ->decreaseFreezeAmount($this->user->id, 'USDT', $remaining_order_usdt_site);
                                                     }
                                                 }

                                                 // Send wallet notification
                                                 UserWalletsRepository::sendWalletUpdateNotificationOrder($temp_order->id, $reduced_wallet);

                                                 break;

                                             case OrdersList::SELL_ROLE: /* sell */
                                                 $title = 'update_sell_order_title';
                                                 /*if (@$temp_order->received_type && $temp_order->received_type == 'IRR') {

                                                         $reduced_wallet = $this->userWalletsRepository
                                                             ->unfreezeAmount($this->user->id, $wallet_name, $remaining_order_qty_user);

                                                         $this->userWalletsRepository
                                                             ->decreaseFreezeAmount($this->user->id, $wallet_name, $remaining_order_qty_site);

                                                     } else {*/
                                                 if ($give_all_back) {
                                                     $reduced_wallet = $this->userWalletsRepository
                                                         ->unfreezeAmount($this->user->id, $wallet_name, $temp_order->amount);
                                                 } else {
                                                     $reduced_wallet = $this->userWalletsRepository
                                                         ->unfreezeAmount($this->user->id, $wallet_name, $remaining_order_qty_user);

                                                     $this->userWalletsRepository
                                                         ->decreaseFreezeAmount($this->user->id, $wallet_name, $remaining_order_qty_site);
                                                 }
                                                 //}

                                                 // Send wallet notification
                                                 UserWalletsRepository::sendWalletUpdateNotificationOrder($temp_order->id, $reduced_wallet);

                                                 break;
                                         }

                                         // Send notification to user
                                         unset($message, $title);
                                         $message = 'update_cancel_order_content' . ':' . $order_id;
                                         $title = 'update_order_title' . ':' . $this->order->market . ':' . number_format($this->order->amount, 2);
                                         // Notification::send($this->user, new UserMessagesNotification($this->user, $message, $title));
                                         UserMessageNotificationJob::dispatch($this->user, $message, $title);

                                         $message = NotificationRepository::prepareNotificationParameter($message);
                                         $title = NotificationRepository::prepareNotificationParameterTitle($title);
                                         // $this->sendSuccessfulNotification($title, $message);

                                         NotificationRepository::sendSuccessfulNotification($this->order->user_id, $title, $message);

                                         // SmsHelper::sendMessage($this->user->mobile, $this->templates_id['limit_order_cancel'], [$new_updates['params'][$i]['market'], $order_id]);

                                         SmsJob::dispatch($this->user->mobile, $this->templates_id['limit_order_cancel'], [$new_updates['params'][$i]['market'], $order_id]);

                                         //Calculate percentage done
                                         if ($last_deal_amount)
                                             $percentage_done = $this->CalculatePercentageDone($max_qty_user, $last_deal_amount);
                                         else
                                             $percentage_done = 0;

                                         // Update order list to cancel
                                         $this->order = OrdersList::updateLimitOrders($order_id, OrdersList::CANCEL_STATUS, $percentage_done);

                                         // Notification with api
                                         $this->sendSuccessfulOrderNotification();
                                     }
                                     break;
                             }

                             // Set flag to true
                             $success = true;
                             /*}
                             else {
                                 // Set flag to false
                                 //return Response::failed("این سفارش قبلا پردازش شده است.", 403, -3);
                             }*/
                         }

                         // Update update order log status
                         if ($success)
                             UpdateOrderLog::updateStatus($update_order_id, '1');
                         else
                             UpdateOrderLog::updateStatus($update_order_id, '-1');

                         DB::commit();

                         if ($success)
                             return Response::success(null, null, 200);
                         else
                             return Response::failed(null, null, 403, -3);
                     } else {

                         UpdateOrderLog::updateStatus($update_order_id, '-1');
                         DB::commit();
                         return Response::failed(null, null, 403, -3);
                     }
                 }
             }
             // End of proccess

         } catch (\Exception $e) {

             DB::rollBack();

             $new_updates = $request->getContent();

             $log = [
                 'controller' => 'UpdateOrdersController',
                 'method' => 'store',
                 'data' => json_encode($new_updates),
                 'error' => $e->getMessage() . ' in : ' . $e->getLine(),
             ];

             // Store console error
             $log = [
                 'update_request' => json_encode($new_updates),
                 'error' => $e->getMessage() . ' in : ' . $e->getLine(),
             ];
             MongoUpdatesError::store($log);
         }

         UpdateOrdersJob::dispatch($request, $this->tether_price, $this->done_order, $this->info, $this->order, $this->user, $this->templates_id);
    }




    private function sendSuccessfulOrderNotification()
    {
         switch ($this->order->role) {
             case OrdersList::BUY_ROLE:
                 $role = OrdersList::BUY_ROLE;
                 break;
             case OrdersList::SELL_ROLE:
                 $role = OrdersList::SELL_ROLE;
                 break;
         }

         $this->definePriceType($this->order, $role);
         $this->defineOrderPrice($this->order, $role);

         $userId = $this->user->id;
         $messageData = [
             'type' => NotificationHelper::ORDER,
             'id' => $this->order->id,
             'time' => $this->order->time,
             'market' => $this->order->market,
             'order_type' => $this->order->type,
             'role' => $this->order->role,
             'order_price' => $this->order_price, // new
             'order_price_type' => $this->order_price_type, // new
             'avg_price' => $this->order->avg_price,
             'amount' => $this->order->amount,
             'total_price' => $this->total_price, // new
             'total_price_type' => $this->total_price_type, // new
             'current_wage' => $this->order->current_wage,
             'currency_price' => $this->order->currency_price,
             'filled' => $this->order->filled,
             'status' => $this->order->status,
         ];
         $this->notificationHelper->sendNotification($userId, $messageData);

        $this->orderRepository::sendOrderUpdateNotification($this->order);

        try {
            $userId = $this->user ? $this->user->id : null;

            event(new \App\Events\UserWalletUpdated($userId));

            $error_data = [
                'user_id' => $userId,
            ];

            $log = [
                'controller' => 'updateOrderController',
                'method' => 'wallet',
                'data' => json_encode($error_data),
                'error' => 'No error occurred.',
            ];

            Logs::store($log);
        } catch (\Exception $exception) {
            $userId = $this->user ? $this->user->id : null;

            $error_data = [
                'user_id' => $userId,
            ];

            $log = [
                'controller' => 'updateOrderController',
                'method' => 'wallet',
                'data' => json_encode($error_data),
                'error' => $exception->getMessage() . ' : in ' . $exception->getLine() . ' line.',
            ];

            // Append stack trace to the 'error' property
            $log['error'] .= PHP_EOL . $exception->getTraceAsString();

            Logs::store($log);

            return Response::failed($exception->getMessage() . ' : in ' . $exception->getLine() . ' line.', null, 500, -1);
        }
    }

    private function fillUserVariable($user_id)
    {
        $this->user = User::findById($user_id);
    }

    /**
     * Calculates amount done in percentage
     */
    private function CalculatePercentageDone($max_qty_user, $deal_amount)
    {
        return ($deal_amount * 100) / $max_qty_user;
    }

    private function sendSuccessfulNotification($title, $message)
    {
        $userId = $this->user->id;
        $messageData = [
            'type' => NotificationHelper::NOTIFICATION,
            'title' => $title,
            'content' => trans($message),
        ];
        $this->notificationHelper->sendNotification($userId, $messageData);
    }

    private function defineOrderStatus()
    {
        // Define order status to update
        switch ($this->info->status) {

            case 'not_deal':
                break;

            case 'part_deal':
                $status = [
                    'status' => self::STR_PART_DEAL
                ];
                return array_merge($this->done_order, $status);

            case 'done':
                $status = [
                    'status' => self::STR_DONE_DEAL
                ];
                return array_merge($this->done_order, $status);

            case 'cancel':
                $status = [
                    'status' => self::STR_CANCEL_DEAL
                ];
                return array_merge($this->done_order, $status);
        }
    }

    private function definePriceType($search_result, $role)
    {
        switch ($role) {

            case OrdersList::BUY_ROLE:
                switch ($search_result->payment_type) {
                    case 'IRR':
                        $this->order_price_type = OrdersList::IRT;
                        $this->total_price_type = OrdersList::IRT;
                        break;
                    case 'USDT':
                        $this->order_price_type = OrdersList::USDT;
                        $this->total_price_type = OrdersList::USDT;
                        break;
                }
                break;


            case OrdersList::SELL_ROLE:
                switch ($search_result->received_type) {
                    case 'IRR':
                        $this->order_price_type = OrdersList::IRT;
                        $this->total_price_type = OrdersList::IRT;
                        break;
                    case 'USDT':
                        $this->order_price_type = OrdersList::USDT;
                        $this->total_price_type = OrdersList::USDT;
                        break;
                }
                break;
        }
    }

    private function defineOrderPrice($search_result, $role)
    {

        switch ($role) {

            case OrdersList::BUY_ROLE:
                switch ($search_result->payment_type) {
                    case 'IRR':
                        $this->order_price = $search_result->irt_price;
                        $this->total_price = $search_result->total_irt_price;
                        break;
                    case 'USDT':
                        $this->order_price = $search_result->usdt_price;
                        $this->total_price = $search_result->total_usdt_price;
                        break;
                }
                break;


            case OrdersList::SELL_ROLE:
                switch ($search_result->received_type) {
                    case 'IRR':
                        $this->order_price = $search_result->irt_price;
                        $this->total_price = $search_result->total_irt_price;
                        break;
                    case 'USDT':
                        $this->order_price = $search_result->usdt_price;
                        $this->total_price = $search_result->total_usdt_price;
                        break;
                }
                break;
        }
    }
}
