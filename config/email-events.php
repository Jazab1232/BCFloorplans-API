<?php

return [
    'order_created' => [
        'label' => 'Order Created',
        'description' => 'Sent when a new order is created',
        'recipients' => ['admin', 'agent'], // Vendor removed per user feedback
        'defaults' => [
            'admin' => true,
            'agent' => true,
        ],
        'variables' => ['order_id', 'property_address', 'agent_name', 'amount'],
        'mailable_class' => \App\Mail\OrderCreated::class,
    ],
    'order_updated' => [
        'label' => 'Order Updated',
        'description' => 'Sent when an order is updated',
        'recipients' => ['admin', 'agent', 'vendor'],
        'defaults' => [
            'admin' => true,
            'agent' => true,
            'vendor' => true,
        ],
        'variables' => ['order_id', 'property_address', 'changes_summary'],
        'mailable_class' => \App\Mail\OrderUpdated::class,
    ],
    'order_cancelled' => [
        'label' => 'Order Cancelled',
        'description' => 'Sent when an order is cancelled',
        'recipients' => ['admin', 'agent', 'vendor'],
        'defaults' => [
            'admin' => true,
            'agent' => true,
            'vendor' => true,
        ],
        'variables' => ['order_id', 'property_address', 'cancellation_reason', 'cancellation_fee'],
        'mailable_class' => \App\Mail\OrderCancelled::class,
    ],
    'slot_booked' => [
        'label' => 'Slot Booked / Appointment Scheduled',
        'description' => 'Sent when a service appointment is scheduled/booked',
        'recipients' => ['admin', 'agent', 'vendor'],
        'defaults' => [
            'admin' => true,
            'agent' => false,
            'vendor' => true,
        ],
        'variables' => ['order_id', 'property_address', 'service_name', 'date', 'start_time', 'end_time'],
        'mailable_class' => \App\Mail\SlotBooked::class,
    ],
    'slot_cancelled' => [
        'label' => 'Slot Cancelled',
        'description' => 'Sent when an individual service appointment is cancelled',
        'recipients' => ['admin', 'agent', 'vendor'],
        'defaults' => [
            'admin' => true,
            'agent' => false,
            'vendor' => true,
        ],
        'variables' => ['order_id', 'property_address', 'service_name'],
        'mailable_class' => \App\Mail\SlotCancelled::class,
    ],
    'slot_rescheduled' => [
        'label' => 'Appointment Rescheduled',
        'description' => 'Sent when a scheduled service appointment is moved to a new time',
        'recipients' => ['admin', 'agent', 'vendor'],
        'defaults' => [
            'admin' => true,
            'agent' => false,
            'vendor' => true,
        ],
        'variables' => ['order_id', 'property_address', 'service_name', 'old_date', 'new_date', 'old_time', 'new_time'],
        'mailable_class' => \App\Mail\SlotRescheduled::class,
    ],
    'booking_reminder' => [
        'label' => 'Booking Reminder',
        'description' => 'Upcoming service reminder (e.g. 24 hours before)',
        'recipients' => ['agent', 'vendor'],
        'defaults' => [
            'agent' => false,
            'vendor' => true,
        ],
        'variables' => ['order_id', 'property_address', 'service_name', 'date', 'start_time'],
        'mailable_class' => \App\Mail\BookingReminder::class,
    ],
    'agent_payment_received' => [
        'label' => 'Agent Payment Received',
        'description' => 'Sent when payment is received from an agent',
        'recipients' => ['admin', 'agent'],
        'defaults' => [
            'admin' => true,
            'agent' => true,
        ],
        'variables' => ['order_id', 'property_address', 'amount', 'payment_method'],
        'mailable_class' => \App\Mail\AgentPaymentReceived::class,
    ],
    'vendor_payment_processed' => [
        'label' => 'Vendor Payout Processed',
        'description' => 'Sent when payout to a vendor is processed successfully',
        'recipients' => ['admin', 'vendor'],
        'defaults' => [
            'admin' => true,
            'vendor' => true,
        ],
        'variables' => ['amount', 'transfer_id', 'service_count'],
        'mailable_class' => \App\Mail\VendorPaymentProcessed::class,
    ],
    'invoice_created' => [
        'label' => 'Invoice Created',
        'description' => 'Sent when a new invoice is generated',
        'recipients' => ['admin', 'agent'],
        'defaults' => [
            'admin' => false,
            'agent' => true,
        ],
        'variables' => ['invoice_id', 'order_id', 'amount', 'property_address'],
        'mailable_class' => \App\Mail\InvoiceCreated::class,
    ],
    'password_reset' => [
        'label' => 'Password Reset',
        'description' => 'System security email for resetting user account password',
        'recipients' => ['admin', 'agent', 'vendor'],
        'defaults' => [
            'admin' => true,
            'agent' => true,
            'vendor' => true,
        ],
        'always_send' => true,
    ],
    'matterport_expiry_reminder' => [
        'label' => '3D Tour / Matterport Expiry Reminder',
        'description' => 'Sent before a 3D Tour hosting period expires (e.g. 30d, 14d, 7d, 1d)',
        'recipients' => ['admin', 'agent'],
        'defaults' => [
            'admin' => true,
            'agent' => true,
        ],
        'variables' => ['property_address', 'expiry_date', 'days_remaining', 'renewal_url', 'agent_name'],
    ],
    'matterport_expired' => [
        'label' => '3D Tour / Matterport Expired',
        'description' => 'Sent on the day a 3D Tour hosting period expires',
        'recipients' => ['admin', 'agent'],
        'defaults' => [
            'admin' => true,
            'agent' => true,
        ],
        'variables' => ['property_address', 'expiry_date', 'renewal_url', 'agent_name'],
    ],
    'matterport_renewed' => [
        'label' => '3D Tour / Matterport Hosting Renewed',
        'description' => 'Sent when a 3D Tour hosting period has been renewed successfully',
        'recipients' => ['admin', 'agent'],
        'defaults' => [
            'admin' => true,
            'agent' => true,
        ],
        'variables' => ['property_address', 'new_expiry_date', 'duration_months', 'amount', 'agent_name'],
    ],
];

