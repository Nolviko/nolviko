<?php

declare(strict_types=1);

return [
    'installer.mail.heading' => 'Giden e-posta (isteğe bağlı)',
    'installer.mail.intro' => 'Hosting e-posta hizmetini veya SMTP sunucusunu seçin. SMTP '
        . 'adımını atlayarak kuruluma devam edebilirsiniz.',
    'installer.mail.transport' => 'Gönderim yöntemi',
    'installer.mail.native' => 'Hosting PHP e-posta hizmeti',
    'installer.mail.smtp_transport' => 'SMTP',
    'installer.mail.smtp' => 'SMTP ayarları',
    'installer.mail.from_address' => 'Gönderen e-posta adresi',
    'installer.mail.from_name' => 'Gönderen adı',
    'installer.mail.host' => 'SMTP sunucusu',
    'installer.mail.port' => 'SMTP portu (boş: STARTTLS için 587, SMTPS için 465)',
    'installer.mail.encryption' => 'Şifreleme',
    'installer.mail.user' => 'SMTP kullanıcı adı',
    'installer.mail.password' => 'SMTP parolası',
    'installer.mail.tls' => 'SMTP için TLS ve geçerli sunucu sertifikası zorunludur.',
    'installer.mail.recipient' => 'Test iletisi alıcı adresi',
    'installer.mail.test' => 'Kaydetmeden test iletisi gönder',
    'installer.mail.save' => 'Kaydet ve devam et',
    'installer.mail.skip' => 'SMTP adımını atla ve devam et',
    'installer.mail.sent' => 'Sunucu test iletisini kabul etti. Alıcının gelen kutusunu kontrol'
        . ' edin; ayarlar henüz kaydedilmedi.',
    'installer.mail.password_notice' => 'Parola yeniden gösterilmez. Testten sonra kaydetmeden önce tekrar girin.',
    'installer.mail.skip_notice' => 'Atlama, hosting PHP e-posta hizmetini seçer. Gönderen '
        . 'belirtilmemişse ilk yöneticinin e-posta adresi kullanılır. '
        . 'Kurulumdan sonra yönetim panelinden gönderimi kontrol edin.',
    'installer.prerequisites.intro' => 'Bu sayfa sunucunun forumu çalıştırıp çalıştıramadığını denetler. Başarısız '
        . 'olan her maddeyi düzeltip yeniden kontrol edin.',
    'installer.prerequisites.runtime' => 'PHP',
    'installer.prerequisites.files' => 'Dosyalar ve web erişimi',
    'installer.prerequisites.ready' => 'Gerekli tüm kontroller başarılı. Sırada veritabanı ayarları var.',
    'installer.prerequisites.blocked' => 'Bazı gerekli kontroller başarısız. Bunları düzeltip yeniden kontrol edin.',
    'installer.prerequisites.recheck' => 'Yeniden kontrol et',
    'installer.prerequisites.continue' => 'Veritabanı ayarlarına devam et',
    'installer.form.errors' => 'Lütfen şunları düzeltin:',
    'installer.setup_code.label' => 'Kurulum kodu',
    'installer.setup_code.help' => 'Bu sitenin sahibi olduğunuzu kanıtlamak için sunucunuzdaki {file} dosyasını açın'
        . ' (örneğin hosting dosya yöneticisi veya FTP ile) ve içindeki kodu girin.',
    'installer.database.heading' => 'Veritabanı',
    'installer.database.driver' => 'Veritabanı türü',
    'installer.database.driver_mysql' => 'MySQL 8.4 veya MariaDB 10.11+',
    'installer.database.driver_postgresql' => 'PostgreSQL 16+',
    'installer.database.driver_help' => 'MySQL ile MariaDB otomatik olarak ayırt edilir.',
    'installer.database.host' => 'Veritabanı sunucusu',
    'installer.database.port' => 'Port',
    'installer.database.port_help' => 'Varsayılan port için boş bırakın (3306, PostgreSQL 5432).',
    'installer.database.name' => 'Veritabanı adı',
    'installer.database.user' => 'Veritabanı kullanıcı adı',
    'installer.database.password' => 'Veritabanı parolası',
    'installer.database.submit' => 'Kontrol et ve kaydet',
    'installer.migrate.heading' => 'Veritabanı tabloları',
    'installer.migrate.auto' => 'Otomatik olarak devam ediliyor…',
    'installer.migrate.pending' => '{count, plural, one {# veritabanı güncellemesi bekliyor.} other {# veritabanı '
        . 'güncellemesi bekliyor.}}',
    'installer.migrate.submit' => 'Tabloları oluştur',
    'installer.migrate.complete' => 'Veritabanı hazır. Sırada isteğe bağlı giden e-posta ayarları var.',
    'installer.migrate.continue' => 'E-posta ayarlarına devam et',
    'installer.admin.heading' => 'Yönetici hesabı',
    'installer.admin.intro' => 'Bu hesap forumu yönetir. Başka hiçbir yerde kullanmadığınız güçlü bir parola seçin.',
    'installer.admin.username' => 'Kullanıcı adı',
    'installer.admin.email' => 'E-posta adresi',
    'installer.admin.password' => 'Parola',
    'installer.admin.password_confirmation' => 'Parolayı tekrarlayın',
    'installer.admin.submit' => 'Hesabı oluştur ve bitir',
    'installer.admin.exists' => 'Yönetici hesabı zaten var. Forumu açmak için kurulumu tamamlayın.',
    'installer.admin.finish' => 'Kurulumu tamamla',
    'installer.status.passed' => 'Başarılı',
    'installer.status.warning' => 'Uyarı',
    'installer.status.failed' => 'Başarısız',
    'installer.check.php_version' => 'PHP {version} destekleniyor.',
    'installer.check.php_end_of_life' => 'PHP {version} artık PHP projesinden güvenlik güncellemesi almıyor. Forum '
        . 'çalışır, ancak barındırma firmanızdan daha yeni bir PHP sürümü isteyin.',
    'installer.check.extension_missing' => 'Gerekli PHP eklentisi {extension} yüklü değil. Şunun için gerekiyor: '
        . '{feature, select, database {veritabanı bağlantısı} languages {diller, tarihler ve sayılar} text '
        . '{metin işleme} packages {güncellemeler ve eklenti/tema kurulumu} security {parolalar, anahtarlar ve '
        . 'güvenli bağlantılar} uploads {yüklemeler, avatarlar ve görseller} sign_in {oturum açma} other '
        . '{forum}}. Barındırma firmanızdan etkinleştirmesini isteyin.',
    'installer.check.extension' => 'Gerekli PHP eklentisi {extension} yüklü.',
    'installer.check.memory_limit' => 'PHP bellek sınırı: {limit}.',
    'installer.check.storage_writable' => 'Depolama dizinine web sunucusu tarafından yazılabiliyor.',
    'installer.check.web_protected' => '{directory} dizini web üzerinden indirilemiyor.',
    'installer.check.web_exposed' => '{directory} dizinindeki dosyalar web üzerinden indirilebiliyor. Belge kökünü '
        . 'public/ dizinine yönlendirin veya sunucunun verilen .htaccess dosyalarını '
        . 'uyguladığından emin olun (nginx için hosting kılavuzundaki örnek yapılandırma '
        . 'gerekir).',
    'installer.check.web_unknown' => '{directory} dizininin korunması HTTP üzerinden denetlenemedi. Kurulumdan sonra'
        . ' dizindeki bir dosyayı tarayıcıda açın; dosya gösterilmemelidir.',
    'installer.mail.starttls' => 'STARTTLS',
    'installer.mail.smtps' => 'SMTPS',
    'installer.steps.label' => 'Kurulum adımları',
    'installer.steps.prerequisites' => 'Sunucu kontrolü',
    'installer.steps.database' => 'Veritabanı',
    'installer.steps.migrate' => 'Tablolar',
    'installer.steps.mail' => 'E-posta',
    'installer.steps.admin' => 'Yönetici',
    'installer.admin.accent' => 'Forumun vurgu rengi',
    'installer.admin.report'
        => 'Bu kurulumu Nolviko ekibine bildir. Paylaşılacak bilgiler: {domain} ve Nolviko {version}.',
    'installer.admin.accent_hint' => 'Daha sonra Yönetim, Site ayarları bölümünden değiştirebilirsiniz.',
];
