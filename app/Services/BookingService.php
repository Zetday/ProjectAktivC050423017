<?php

namespace App\Services;

use Excepiton;
use App\Models\BookingTransaction;
use Illuminate\Support\Facades\DB;
use App\Models\WorkshopParticipant;
use Illuminate\Support\Facades\Log;
use App\Repositories\Contracts\BookingRepositoryInterface;
use App\Repositories\Contracts\WorkshopRepositoryInterface;

class BookingService
{
    protected $bookingRepository;
    protected $workshopRepository;

    public function __construct(
        WorkshopRepositoryInterface $workshopRepository,
        BookingRepositoryInterface $bookingRepository
    ) {
        $this->bookingRepository = $bookingRepository;
        $this->workshopRepository = $workshopRepository;
    }

    public function storeBooking(array $validatedData)
    {
        $existingData = $this->bookingRepository->getOrderDataFromSession();

        $updatedData = array_merge($existingData, $validatedData);

        $this->bookingRepository->saveToSession($updatedData);

        return $updatedData;
    }

    public function isBookingSessionAvailable()
    {
        return $this->bookingRepository->getOrderDataFromSession() !== null;
    }

    public function getBookingDetails()
    {
        $orderData = $this->bookingRepository->getOrderDataFromSession();

        if (empty($orderData)) {
            return null;
        }

        $workshop = $this->workshopRepository->find($orderData['workshop_id']);

        $quantity = isset($orderData['quantity']) ? $orderData['quantity'] : 1;
        $subTotalAmount  = $workshop->price * $quantity;

        $taxRate = 0.11;
        $totalTax = $subTotalAmount * $taxRate;

        $totalAmount = $subTotalAmount + $totalTax;

        $orderData['sub_total_amount'] = $subTotalAmount;
        $orderData['sub_total_amount'] = $totalTax;
        $orderData['sub_total_amount'] = $totalAmount;

        $this->bookingRepository->saveToSession($orderData);

        return compact('orderData', 'workshop');
    }
    public function finalizeBookingAndPayment(array $paymentData)
    {
        $orderData = $this->bookingRepository->getOrderDataFromSession();

        if (!$orderData) {
            throw new \Exception('Booking data is missing form session. ');
        }

        Log::info('Order Data:', $orderData);

        if (!isset($orderData['total_amount'])) {
            $proofPath = $paymentData['proof']->store('proofs', 'public');
        }

        DB::beginTransaction();

        try {
            $bookingTransaction = BookingTransaction::create([
                'name' => $orderData['name'],
                'email' => $orderData['email'],
                'phone' => $orderData['phone'],
                'customer_bank_name' => $orderData['customer_bank_name'],
                'customer_bank_number' => $orderData['customer_bank_number'],
                'proof' => $proofPath,
                'quantity' => $orderData['quantity'],
                'total_amount' => $orderData['total_amount'],
                'is_paid' => false,
                'workshop_id' => $orderData['workshop_id'],
                'booking_trx_id' => BookingTransaction::generateUniqueTrxId(),

            ]);

            foreach ($orderData['participants'] as $participant) {
                WorkshopParticipant::create([
                    'name' => $participant['name'],
                    'occupation' => $participant['occupation'],
                    'email' => $participant['email'],
                    'workshop_id' => $bookingTransaction->workshop_id,
                    'booking_transaction_id' => $bookingTransaction->id,
                ]);
            }

            DB::commit();

            $this->bookingRepository->clearSession();

            return $bookingTransaction->id;
        } catch (\Exception $e) {
            Log::error('Payment processing failed: ' . $e->getMessage());

            DB::rollBack();

            throw $e;
        }
    }

    public function getMyBookingDetails(array $validated)
    {
        return $this->bookingRepository->findByTrxIdAndPhoneNumber(
            $validated['booking_trx_id'],
            $validated['phone']
        );
    }
}
