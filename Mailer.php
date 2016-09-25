<?php

class Mailer implements \SplObserver
{
    public function update(SplSubject $subject, $changedData = null)
    {
        if ($changedData['new_qoh'] < 5 && ($changedData['new_qoh'] != $changedData['old_qoh'])) {
            $to = 'nobody@example.com';
            $subject = 'Low qoh';
            $message = "Warning!Product with sku" .  $changedData['sku'] . " has " . $changedData['new_qoh'] .
                       " quantity  on hand\r\n";
            $headers = 'From: webmaster@example.com' . "\r\n" .
                'Reply-To: webmaster@example.com' . "\r\n" .
                'X-Mailer: PHP/' . phpversion();

            print_r($message);
            //mail($to, $subject, $message, $headers);
        }

        return;
    }
}