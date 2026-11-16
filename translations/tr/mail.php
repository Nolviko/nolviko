<?php

declare(strict_types=1);

return [
    'mail.footer' => 'Bu ileti forum tarafından otomatik gönderildi. Lütfen bu iletiyi yanıtlamayın.',
    'mail.test.subject' => 'Forumunuzdan test iletisi',
    'mail.test.greeting' => 'Merhaba {name},',
    'mail.test.body' => 'Bu bir test iletisidir. Okuyabiliyorsanız forum e-posta gönderebiliyor.',
    'mail.verify_email.subject' => 'E-posta adresinizi doğrulayın',
    'mail.verify_email.greeting' => 'Merhaba {name},',
    'mail.verify_email.body' => 'Kaydolduğunuz için teşekkürler. E-posta adresinizi doğrulayıp '
        . 'hesabınızı etkinleştirmek için bu bağlantıyı açın:',
    'mail.verify_email.button' => 'E-posta adresimi doğrula',
    'mail.verify_email.expiry' => 'Bağlantı 24 saat geçerlidir ve bir kez kullanılabilir.',
    'mail.verify_email.not_you' => 'Kaydolmadıysanız bu e-postayı dikkate almayın; bağlantı '
        . 'kullanılmadan hesap etkinleşmez.',
    'mail.password_reset.subject' => 'Yeni parola seçin',
    'mail.password_reset.greeting' => 'Merhaba {name},',
    'mail.password_reset.body' => 'Hesabınız için yeni parola istendi. Yeni parola seçmek için bu bağlantıyı açın:',
    'mail.password_reset.button' => 'Yeni parola seç',
    'mail.password_reset.expiry' => 'Bağlantı bir saat geçerlidir ve bir kez kullanılabilir. Yeni '
        . 'parola seçildiğinde tüm cihazlardaki oturumlarınız kapatılır.',
    'mail.password_reset.not_you' => 'Bu isteği siz yapmadıysanız e-postayı dikkate almayın; parolanız değişmez.',
    'mail.email_change.subject' => 'Yeni e-posta adresinizi doğrulayın',
    'mail.email_change.greeting' => 'Merhaba {name},',
    'mail.email_change.body' => 'Forum hesabınızda bu adresi kullanmak için bu bağlantıyı açın:',
    'mail.email_change.button' => 'Bu adresi kullan',
    'mail.email_change.expiry' => 'Bağlantı 24 saat geçerlidir ve bir kez kullanılabilir.',
    'mail.email_change.not_you' => 'Bu isteği siz yapmadıysanız e-postayı dikkate almayın; hiçbir şey değişmez.',
    'mail.email_change_notice.subject' => 'E-posta adresiniz değiştirilmek üzere',
    'mail.email_change_notice.greeting' => 'Merhaba {name},',
    'mail.email_change_notice.body' => 'Hesabınıza giriş yapan biri başka bir e-posta adresine geçmek '
        . 'istedi. Değişiklik yalnızca yeni adrese gönderilen bağlantı '
        . 'açılınca gerçekleşir.',
    'mail.email_change_notice.not_you' => 'Bu işlemi siz yapmadıysanız parolanızı hemen değiştirin:',
    'mail.already_registered.subject' => 'Birisi e-posta adresinizle kaydolmayı denedi',
    'mail.already_registered.greeting' => 'Merhaba {name},',
    'mail.already_registered.body' => 'Birisi zaten hesabınıza ait olan bu e-posta adresiyle yeni hesap '
        . 'açmayı denedi. İkinci bir hesap oluşturulmadı.',
    'mail.already_registered.login' => 'İşlemi siz yaptıysanız buradan giriş yapın:',
    'mail.already_registered.resend' => 'Adresinizi henüz doğrulamadıysanız buradan yeni bağlantı isteyin:',
    'mail.already_registered.not_you' => 'İşlemi siz yapmadıysanız bir şey yapmanız gerekmez.',
    'mail.second_factor.subject' => 'İki adımlı doğrulama değişti',
    'mail.second_factor.enabled' => 'Hesabınızda iki adımlı doğrulama açıldı.',
    'mail.second_factor.disabled' => 'Hesabınızda iki adımlı doğrulama kapatıldı.',
    'mail.second_factor.reset' => 'Bir yönetici hesabınızın iki adımlı doğrulamasını kapattı.',
    'mail.second_factor.not_you' => 'Bu işlemi siz yapmadıysanız parolanızı değiştirin ve hesabınızı kontrol edin:',
];
