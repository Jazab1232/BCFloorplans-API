<?php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// class SystemNotification extends Notification
// {
//     use Queueable;

//     protected string $subject;
//     protected string $view;
//     protected array $data;

//     public function __construct(
//         string $subject,
//         string $view,
//         array $data = []
//     ) {
//         $this->subject = $subject;
//         $this->view    = $view;
//         $this->data    = $data;
//     }

//     public function via(object $notifiable): array
//     {
//         return ['mail'];
//     }

//     public function toMail(object $notifiable): MailMessage
//     {
//         return (new MailMessage)
//             ->subject($this->subject)
//             ->view($this->view, array_merge(
//                 $this->data,
//                 ['notifiable' => $notifiable]
//             ));
//     }
// }

class SystemNotification extends Notification
{
    use Queueable;

    protected string $subject;
    protected string $html;

    public function __construct(string $subject, string $html)
    {
        $this->subject = $subject;
        $this->html    = $html;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->view('emails.system', [
                'html' => $this->html,
                'subject' => $this->subject,
                'notifiable' => $notifiable,
            ]);
    }
}

