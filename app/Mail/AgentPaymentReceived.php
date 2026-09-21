<?php

namespace App\Mail;

use App\Models\AgentPayment;
use App\Models\Order;
use App\Models\OrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AgentPaymentReceived extends Mailable
{
    use Queueable, SerializesModels;

    public $payment;
    public $order;
    public $orderService;
    public $recipientName;
    public $recipientRole;
    public $data;

    /**
     * Create a new message instance.
     */
    public function __construct(
        AgentPayment $payment, 
        Order $order = null, 
        OrderService $orderService = null, 
        string $recipientName = 'Customer',
        string $recipientRole = 'agent',
        array $data = []
    ) {
        $this->payment = $payment;
        $this->order = $order;
        $this->orderService = $orderService;
        $this->recipientName = $recipientName;
        $this->recipientRole = $recipientRole;
        $this->data = $data;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $amountStr = '$' . number_format((float)$this->payment->amount, 2);
        $address = $this->order ? $this->order->property_address : ($this->data['property_address'] ?? null);
        $scope = $this->data['payment_scope_description'] ?? null;

        if ($this->recipientRole === 'admin') {
            $subject = 'Payment Received: ' . $amountStr;
            if ($scope) {
                $subject .= ' (' . $scope . ')';
            }
            if ($address && $address !== 'N/A') {
                $subject .= ' for ' . $address;
            } elseif ($this->order) {
                $subject .= ' for Order #' . $this->order->id;
            }
        } else {
            $subject = 'Payment Successful: ' . $amountStr;
            if ($scope && !empty($this->data['is_partial'])) {
                $subject .= ' (' . $scope . ')';
            }
            if ($address && $address !== 'N/A') {
                $subject .= ' for ' . $address;
            } elseif ($this->order) {
                $subject .= ' for Order #' . $this->order->id;
            }
        }

        return new Envelope(subject: $subject);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.agent_payment_received',
            with: [
                'payment' => $this->payment,
                'order' => $this->order,
                'orderService' => $this->orderService,
                'recipientName' => $this->recipientName,
                'recipientRole' => $this->recipientRole,
                'data' => $this->data,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
