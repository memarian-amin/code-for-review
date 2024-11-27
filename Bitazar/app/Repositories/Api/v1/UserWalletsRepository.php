<?php

namespace App\Repositories\Api\v1;

use App\Helpers\FreezeHelper;
use App\Models\Api\v1\FreezeLog;
use App\Models\Api\v1\UsdtPrice;
use App\Jobs\SendNotificationJob;
use App\Models\Api\v1\OrdersList;
use App\Models\Api\v1\UserWallets;
use App\Helpers\NotificationHelper;
use App\Models\Api\v1\ExchangeList;
use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;

class UserWalletsRepository
{
    public static function getUserWallet($user_id, $wallet_name)
    {
        if (strtoupper($wallet_name) == "IRT") $wallet_name = "IRR";

        $user_wallet = UserWallets::query()
            ->where([
                'user_id' => $user_id,
                'wallet' => $wallet_name
            ])->first();
        if (!$user_wallet) {
            $exchange = ExchangeList::query()->where('symbol', $wallet_name)->first(['id']);
            $user_wallet = new UserWallets;
            $user_wallet->user_id = $user_id;
            $user_wallet->exchange_id = @$exchange->id;
            $user_wallet->amount = 0;
            $user_wallet->wallet = $wallet_name;
            $user_wallet->status = 1;
            $user_wallet->save();
            return $user_wallet;
        } else {
            return $user_wallet;
        }
    }

    /**
     * Charge the specified amount to a user's IRR wallet.
     *
     * @param float $amount The amount to be charged.
     * @param int|null $user_id The user ID. If null, the authenticated user's ID will be used.
     *
     * @throws \Exception If user or wallet is not found.
     */
    public static function chargeTomanWallet($amount, $user_id = null, $send_notification = false)
    {
        try {
            // Determine the user ID to use (provided or authenticated)
            $user_id = $user_id ?? Auth::guard('api')->id();

            // Find the user's IRR wallet
            $wallet = UserWallets::where([
                'user_id' => $user_id,
                'wallet' => 'IRR'
            ])->first();

            // If wallet is not found, throw an exception
            if (!$wallet) {
                throw new \Exception("User or wallet not found.");
            }

            // Update the user's wallet amount and save
            $wallet->amount += $amount;
            $wallet->save();

            Cache::forget('wallet_list_' . $user_id);

            if ($send_notification)
                self::sendWalletUpdateNotification($wallet);

            return true;
        } catch (\Exception $e) {
            // Handle and log the exception as needed
            // Example: Log::error($e->getMessage());
            throw $e; // Re-throw the exception after handling
        }
    }

    public static function chargeTomanWalletWithAuth($amount, $send_notification = false)
    {
        $wallet = UserWallets::query()
            ->where([
                'user_id' => Auth::id(),
                'wallet' => 'IRR'
            ])->first();

        $wallet->amount += $amount;
        $wallet->save();

        Cache::forget('wallet_list_' . $wallet->user_id);

        if ($send_notification)
            self::sendWalletUpdateNotification($wallet);
    }

    public static function increaseWalletAmount($user_id, $wallet_name, $amount, $send_notification = false)
    {
        if (strtoupper($wallet_name) == "IRT") $wallet_name = "IRR";

        $user_wallet = UserWallets::query()
            ->where([
                'user_id' => $user_id,
                'wallet' => $wallet_name,
            ])->first();
        if (!$user_wallet) {
            $exchange = ExchangeList::query()->where('symbol', $wallet_name)->first(['id']);
            $user_wallet = new UserWallets;
            $user_wallet->user_id = $user_id;
            $user_wallet->exchange_id = $exchange->id;
            $user_wallet->amount = $amount;
            $user_wallet->wallet = $wallet_name;
            $user_wallet->status = 1;
            $user_wallet->save();

            Cache::forget('wallet_list_' . $user_wallet->user_id);

            return $user_wallet;
        }
        $user_wallet->amount += $amount;
        $user_wallet->save();

        Cache::forget('wallet_list_' . $user_wallet->user_id);

        if ($send_notification)
            self::sendWalletUpdateNotification($user_wallet);

        return $user_wallet;
    }

    public static function decreaseWalletAmount($user_id, $wallet_name, $amount, $send_notification = false)
    {
        if (strtoupper($wallet_name) == "IRT") $wallet_name = "IRR";

        $user_wallet = UserWallets::query()
            ->where([
                'user_id' => $user_id,
                'wallet' => $wallet_name,
            ])->first();
        $user_wallet->amount = abs($amount - $user_wallet->amount);
        $user_wallet->save();

        if ($send_notification)
            self::sendWalletUpdateNotification($user_wallet);

        Cache::forget('wallet_list_' . $user_wallet->user_id);

        return $user_wallet;
    }

    public static function chargeUserWalletAfterDeposit($user_id, $deposit_amount, $wallet_name, $send_notification = false)
    {
        $wallet = UserWallets::query()->where([
            'user_id' => $user_id,
            'wallet' => $wallet_name,
        ])->first();

        if (!$wallet) {
            $wallet = new self;
            $wallet->user_id = $user_id;
            $wallet->wallet = $wallet_name;
        }

        $wallet->amount += $deposit_amount;
        $wallet->save();

        Cache::forget('wallet_list_' . $wallet->user_id);

        if ($send_notification)
            self::sendWalletUpdateNotification($wallet);
    }

    public static function getWallets($user_id)
    {
        return UserWallets::query()->where('user_id', $user_id)->where('amount', '>=', 0)->get();
    }

    public static function getWalletByCurrency($user_id, $currency)
    {
        return UserWallets::query()->where([
            'user_id' => $user_id,
            'wallet' => $currency
        ])->where('amount', '>=', 0)->get();
    }


    /**
     * Freeze the specified amount in the user's wallet.
     * Used when submitting limit buy or sell order
     *
     * @param int    $user_id
     * @param string $wallet_name
     * @param float  $amount_to_freeze
     *
     * @return void
     */
    public static function freezeAmount($user_id, $wallet_name, $amount_to_freeze, $send_notification = false)
    {
        if (strtoupper($wallet_name) == "IRT") $wallet_name = "IRR";

        // Find the user's wallet
        $wallet = UserWallets::query()->where('user_id', $user_id)
            ->where('wallet', strtoupper($wallet_name))
            ->first();

        // Check if the wallet exists and has sufficient funds
        if ($wallet->amount >= $amount_to_freeze) {
            // Update wallet to freeze the specified amount
            // $wallet->update([
            //     'amount' => $wallet->amount - $amount_to_freeze,
            //     'freeze' => $wallet->freeze + $amount_to_freeze,
            // ]);

            $wallet->amount = $wallet->amount - $amount_to_freeze;
            $wallet->freeze = $wallet->freeze + $amount_to_freeze;
            $wallet->save();

            Cache::forget('wallet_list_' . $wallet->user_id);

            if ($send_notification)
                self::sendWalletUpdateNotification($wallet);

            return $wallet;
        } else {
            // Handle insufficient funds or wallet not found error
            return false;
        }
    }

    /**
     * Unfreeze the specified amount in the user's wallet.
     * used when transactional query has failed and need to reverse the freezed amount
     *
     * @param int    $user_id
     * @param string $wallet_name
     * @param float  $amount_to_unfreeze
     *
     * @return void
     */
    public static function unfreezeAmount($user_id, $wallet_name, $amount_to_unfreeze,  $send_notification = false)
    {
        if (strtoupper($wallet_name) == "IRT") $wallet_name = "IRR";

        // Find the user's wallet
        $wallet = UserWallets::query()->where('user_id', $user_id)
            ->where('wallet', strtoupper($wallet_name))
            ->first();

        // Check if the wallet exists and has sufficient frozen funds
        if ($wallet && $wallet->freeze >= $amount_to_unfreeze) {
            // Update wallet to unfreeze the specified amount
            $wallet->update([
                'amount' => $wallet->amount + $amount_to_unfreeze,
                'freeze' => $wallet->freeze - $amount_to_unfreeze,
            ]);

            Cache::forget('wallet_list_' . $wallet->user_id);

            if ($send_notification)
                self::sendWalletUpdateNotification($wallet);

            return $wallet;
        } else {
            // Handle insufficient frozen funds or wallet not found error
            return false;
        }
    }

    /**
     * Remove the freeze from the user's wallet.
     *
     *
     * @param int    $user_id
     * @param string $wallet_name
     *
     * @return void
     */
    public static function removeFreeze($user_id, $wallet_name, $send_notification = false)
    {
        if (strtoupper($wallet_name) == "IRT") $wallet_name = "IRR";

        // Find the user's wallet
        $wallet = UserWallets::query()->where('user_id', $user_id)
            ->where('wallet', strtoupper($wallet_name))
            ->first();

        // Check if the wallet exists
        if ($wallet) {
            // Update wallet to remove the freeze
            $wallet->update([
                'amount' => $wallet->amount + $wallet->freeze,
                'freeze' => 0,
            ]);

            Cache::forget('wallet_list_' . $wallet->user_id);

            if ($send_notification)
                self::sendWalletUpdateNotification($wallet);

            return $wallet;
        } else {
            // Handle wallet not found error
            return false;
        }
    }


    /**
     * decrease the freeze from the user's wallet.
     * Used when limit buy or sell is completed successfully
     *
     * @param int    $user_id
     * @param string $wallet_name
     *
     * @return void
     */
    public static function decreaseFreezeAmount($user_id, $wallet_name, $decrease_amount,  $send_notification = false)
    {
        if (strtoupper($wallet_name) == "IRT") $wallet_name = "IRR";

        // Find the user's wallet
        $wallet = UserWallets::query()->where('user_id', $user_id)
            ->where('wallet', strtoupper($wallet_name))
            ->first();

        // Check if the wallet exists
        if ($wallet) {
            // Update wallet to remove the freeze
            $wallet->update([
                'freeze' => $wallet->freeze - $decrease_amount,
            ]);

            Cache::forget('wallet_list_' . $wallet->user_id);

            if ($send_notification)
                self::sendWalletUpdateNotification($wallet);

            return $wallet;
        } else {
            // Handle wallet not found error
            return false;
        }
    }


    public static function freeRemainingFreezeOfOrder($order_id, $send_notification = false)
    {
        try {
            // Load the order based on order_id
            $order = OrdersList::findOrFail($order_id);

            // Get the current filled_qty or filled_irt or filled_usdt and other relevant values
            switch ($order->role) {
                case OrdersList::BUY_ROLE:
                    if (strtoupper($order->payment_type) == 'IRR') {
                        $remaining_freeze = $order->total_irt_price - $order->filled_irt;
                        $wallet_name = $order->total_freezed_asset;
                    } elseif ($order->payment_type == 'USDT') {
                        $remaining_freeze = $order->total_usdt_price - $order->filled_usdt;
                        $wallet_name = $order->total_freezed_asset;
                    }
                    break;
                case OrdersList::SELL_ROLE:
                    $remaining_freeze = $order->amount - $order->filled_qty;
                    $wallet_name = $order->total_freezed_asset;
                    break;
            }

            // decrease the remaining freeze amount
            $wallet = self::decreaseFreezeAmount($order->user_id, $wallet_name, $remaining_freeze);

            // self::logFreezeTransaction($remaining_freeze, $wallet_name, $order->identifier, 'freeRemainingFreezeOfOrder', 'handleWalletUpdatOnLimitUpdate');

            Cache::forget('wallet_list_' . $order->user_id);

            if ($send_notification && $wallet)
                self::sendWalletUpdateNotification($wallet);

            // Return true to indicate success
            return true;
        } catch (ModelNotFoundException $exception) {
            // Handle the case where the order is not found
            // Log the error, return a response, or take other actions
            // Return false to indicate failure
            return false;
        } catch (\Exception $exception) {
            // Handle other exceptions if necessary
            // Log the error, return a response, or take other actions
            // Return false to indicate failure
            return false;
        }
    }


    /**
     * returns freezed amount of a specified wallet
     *
     * @param int    $user_id
     * @param string $wallet_name
     *
     * @return int
     */
    public static function getFreezeAmount($user_id, $wallet_name)
    {
        if (strtoupper($wallet_name) == "IRT") $wallet_name = "IRR";

        // Find the user's wallet
        $wallet = UserWallets::query()->where('user_id', $user_id)
            ->where('wallet', strtoupper($wallet_name))
            ->first();

        // Check if the wallet exists
        if ($wallet) {
            return $wallet->freeze;
        } else {
            // Handle wallet not found error
            return 0;
        }
    }


    private static function calculateWalletValues($user_id, $wallet_name, $amount, $currency_price, $tether_price)
    {
        try {
            if (strtoupper($wallet_name) == "IRT") $wallet_name = "IRR";

            $freeze_amount = FreezeHelper::getFreezeAmount($wallet_name, $user_id);

            if ($amount - $freeze_amount == 0) {
                return ['value' => 0, 'toman_amount' => 0];
            }

            switch ($wallet_name) {
                default:
                    $value = $amount * $currency_price;
                    $toman_amount = $value * $tether_price;
                    break;
                case 'USDT':
                    $value = $amount * $tether_price;
                    $toman_amount = $value;
                    break;
                case 'IRR':
                    $value = $amount;
                    $toman_amount = $value;
                    break;
            }

            return ['value' => $value, 'toman_amount' => $toman_amount, 'freeze' => $freeze_amount];
        } catch (\Exception $e) {
            // Handle the exception, log it, or rethrow if necessary
            return ['value' => -1, 'toman_amount' => -1, 'freeze' => -1];
        }
    }

    /**
     * Send a wallet notification based on an specific order value calculations
     *
     * @param int $order_id The ID of the order.
     * @param object $reduced_wallet The reduced wallet object.
     * @param object|null $increased_wallet The increased wallet object (optional).
     *
     * @return bool True if the notification is sent successfully, false otherwise.
     */
    public static function sendWalletUpdateNotificationOrder($order_id, $reduced_wallet, $increased_wallet = null)
    {
        try {
            // Load order
            $order = OrdersList::findById($order_id);

            // Get current tether price
            $tether_price = UsdtPrice::get()->quantity / 10;

            // Initialize notification service and helper
            $notificationService = new NotificationService;
            $notificationHelper = new NotificationHelper($notificationService);

            // Calculate values for reduced wallet
            $reducedValues = self::calculateWalletValues($order->user_id, $reduced_wallet->wallet, $reduced_wallet->amount, $order->currency_price, $tether_price);

            $messageData = [
                'type' => NotificationHelper::WALLET,
                'id' => $reduced_wallet->id,
                'amount' => $reduced_wallet->amount,
                'freeze' => $reducedValues['freeze'],
                'value' => $reducedValues['value'],
                'toman_amount' => $reducedValues['toman_amount'],
            ];

            // Send realtime notification to user
            // $notificationHelper->sendNotification($order->user_id, $messageData);
            SendNotificationJob::dispatch($order->user_id, $messageData);

            // Calculate values for increased wallet and send notification if applicable
            if ($increased_wallet) {
                $increasedValues = self::calculateWalletValues($order->user_id, $increased_wallet->wallet, $increased_wallet->amount, $order->currency_price, $tether_price);

                $messageData = [
                    'type' => NotificationHelper::WALLET,
                    'id' => $increased_wallet->id,
                    'amount' => $increased_wallet->amount,
                    'freeze' => $increasedValues['freeze'],
                    'value' => $increasedValues['value'],
                    'toman_amount' => $increasedValues['toman_amount'],
                ];

                // Send realtime notification to user
                // $notificationHelper->sendNotification($order->user_id, $messageData);
                SendNotificationJob::dispatch($order->user_id, $messageData);
            }

            return true;
        } catch (\Exception $e) {
            return false;
            // Handle the exception, log it, or rethrow if necessary
        }
    }

    /**
     * Send a wallet update notification to the user.
     *
     * @param \App\Models\Wallet $wallet The wallet instance to send the notification for.
     * @return bool True if the notification is sent successfully, false otherwise.
     */
    public static function sendWalletUpdateNotification($wallet)
    {
        try {
            // Initialize notification service and helper
            $notificationService = new NotificationService;
            $notificationHelper = new NotificationHelper($notificationService);

            // Prepare data for the notification message
            $messageData = [
                'type' => NotificationHelper::WALLET,
                'id' => $wallet->id,
                'wallet' => $wallet->wallet,
                'amount' => $wallet->amount,
                'freeze' => $wallet->freeze,
            ];

            // Send realtime notification to the user
            // $notificationHelper->sendNotification($wallet->user_id, $messageData);
            SendNotificationJob::dispatch($wallet->user_id, $messageData);

            return true;
        } catch (\Exception $e) {
            // Handle the exception, log it, or rethrow if necessary
            return false;
        }
    }

    /**
     * Handle wallet updates on limit update.order from coinex send relatd wallet update notification to user
     *
     * @param int    $user_id
     * @param string $buy_or_sell
     * @param string $irr_or_usdt
     * @param string $wallet_name
     * @param float  $real_filled_qty
     * @param float  $real_filled_irt
     * @param float  $real_filled_usdt
     * @param int    $order_id
     * @param bool   $send_notification default true
     *
     * @return bool True on success, false on error
     */
    public static function logFreezeTransaction($amount, $currency, $identifier, $action, $name,)
    {

        $result = '1';
        if ($amount !== null) {
            $freeze_log = new FreezeLog();
            $freeze_log->identifier = $identifier;
            $freeze_log->amount = $amount;
            $freeze_log->currency = $currency;
            $freeze_log->action = $action;
            $freeze_log->name = $name;
            $freeze_log->result = $result;
            $freeze_log->save();
        } else {
            $freeze_log = new FreezeLog();
            $freeze_log->identifier = $identifier;
            $freeze_log->amount = $amount;
            $freeze_log->currency = $currency;
            $freeze_log->action = $action;
            $freeze_log->name = $name;
            $freeze_log->result = '-1';
            $freeze_log->save();
        }
    }

    public static function handleWalletUpdatOnLimitUpdate($user_id, $buy_or_sell, $irr_or_usdt, $wallet_name, $real_filled_qty, $real_filled_irt, $real_filled_usdt, $order_id, $send_notification = false)
    {
        try {
            $order_log = OrdersList::find($order_id);
            switch ($buy_or_sell) {
                case OrdersList::BUY_ROLE: /* buy */
                    if ($irr_or_usdt && ($irr_or_usdt == 'IRR' || $irr_or_usdt == 'IRT')) {
                        // Decrease IRR freeze amount
                        $reduced_wallet = UserWalletsRepository::decreaseFreezeAmount($user_id, 'IRR', $real_filled_irt);
                        // self::logFreezeTransaction($real_filled_irt, 'IRR', $order_log->identifier, 'decreaseFreezeAmount', 'handleWalletUpdatOnLimitUpdate');

                        // Increase wallet amount for the specified currency
                        $increased_wallet = UserWalletsRepository::increaseWalletAmount($user_id, $wallet_name, $real_filled_qty);
                        // self::logFreezeTransaction($real_filled_qty, $wallet_name, $order_log->identifier, 'increaseWalletAmount', 'handleWalletUpdatOnLimitUpdate');
                    } else {
                        // if USDT
                        // Decrease USDT freeze amount
                        $reduced_wallet = UserWalletsRepository::decreaseFreezeAmount($user_id, 'USDT', $real_filled_usdt);
                        // self::logFreezeTransaction($real_filled_usdt, 'USDT', $order_log->identifier, 'decreaseFreezeAmount', 'handleWalletUpdatOnLimitUpdate');
                        // Increase wallet amount for the specified currency
                        $increased_wallet = UserWalletsRepository::increaseWalletAmount($user_id, $wallet_name, $real_filled_qty);
                        // self::logFreezeTransaction($real_filled_qty, $wallet_name, $order_log->identifier, 'increaseWalletAmount', 'handleWalletUpdatOnLimitUpdate');
                    }
                    // Send wallet notification
                    if ($send_notification)
                        self::sendWalletUpdateNotificationOrder($order_id, $reduced_wallet, $increased_wallet);
                    // TODO add toman amount and value is toman tether and equal toman
                    break;

                case OrdersList::SELL_ROLE: /* sell */
                    if ($irr_or_usdt && ($irr_or_usdt == 'IRR' || $irr_or_usdt == 'IRT')) {
                        // Increase IRR wallet amount
                        $increased_wallet = UserWalletsRepository::increaseWalletAmount($user_id, 'IRR', $real_filled_irt);
                        // Decrease specified currency freeze amount
                        $reduced_wallet = UserWalletsRepository::decreaseFreezeAmount($user_id, $wallet_name, $real_filled_qty);
                    } else {
                        // Increase USDT wallet amount
                        $increased_wallet = UserWalletsRepository::increaseWalletAmount($user_id, 'USDT', $real_filled_usdt);
                        // Decrease specified currency freeze amount
                        $reduced_wallet = UserWalletsRepository::decreaseFreezeAmount($user_id, $wallet_name, $real_filled_qty);
                    }
                    // Send wallet notification
                    if ($send_notification)
                        self::sendWalletUpdateNotificationOrder($order_id, $reduced_wallet, $increased_wallet);

                    break;
            }

            Cache::forget('wallet_list_' . $reduced_wallet->user_id);

            return true; // Success

        } catch (\Exception $exception) {
            // Handle the exception, log the error, return a response, or take other actions
            return false; // Error
        }
    }
}
