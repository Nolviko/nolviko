<?php

declare(strict_types=1);

return [
    'tasks.title' => 'Arka plan görevleri',
    'tasks.cache' => 'İsteğe bağlı önbellek',
    'tasks.policy' => 'Forum cron veya sürekli çalışan bir görev programı olmadan çalışır. '
        . 'Ziyaretler küçük partiler çalıştırır; bu düğme en fazla 3 görev '
        . 'çalıştırır. Teslim zamanı ziyaretlere bağlıdır.',
    'tasks.cache_policy' => 'Redis isteğe bağlıdır ve başlangıçta kapalıdır. Seçilen önbellek '
        . 'özelliklerini açmadan IP bağlantısını deneyin. Yerel olmayan sunucularda '
        . 'doğrulanmış TLS gerekir. Şifreler gösterilmez.',
    'tasks.cache_state' => '{enabled, select, 1 {Redis açık.} other {Dosya önbelleği kullanılıyor.}}',
    'tasks.cli_state' => '{enabled, select, 1 {Komut satırı görev çalıştırıcısı açık.} other {Komut '
        . 'satırı görev çalıştırıcısı kapalı.}}',
    'tasks.fallback' => 'Redis kullanılamadı; forum güvenli biçimde yeni bir dosya önbelleğine '
        . 'döndü. Tekrar bağlanmak için deneyip etkinleştirin.',
    'tasks.host' => 'Sunucu IP adresi',
    'tasks.port' => 'Bağlantı noktası',
    'tasks.username' => 'Redis kullanıcı adı (isteğe bağlı)',
    'tasks.password' => 'Redis şifresi',
    'tasks.database' => 'Redis veritabanı numarası',
    'tasks.tls' => 'Doğrulanmış TLS kullan',
    'tasks.peer' => 'Sertifikadaki sunucu adı (isteğe bağlı)',
    'tasks.features' => 'Ayrı ayrı etkinleştir',
    'tasks.theme_cache' => 'Tema görüntüleme önbelleği',
    'tasks.presence_cache' => 'Yaklaşık çevrimiçi etkinlik önbelleği',
    'tasks.preflight' => 'Bağlantıyı dene',
    'tasks.enable' => 'Dene ve etkinleştir',
    'tasks.disable' => 'Dosya önbelleğine dön',
    'tasks.run' => 'Şimdi küçük bir parti çalıştır',
    'tasks.retry' => 'Başarısız görevi yeniden dene',
    'tasks.cli_enable' => 'Komut satırı çalıştırıcısını aç',
    'tasks.cli_disable' => 'Komut satırı çalıştırıcısını kapat',
    'tasks.status' => 'Durum',
    'tasks.all' => 'Tümü',
    'tasks.filter' => 'Filtrele',
    'tasks.id' => 'Numara',
    'tasks.type' => 'Görev',
    'tasks.attempts' => 'Deneme sayısı',
    'tasks.action' => 'İşlem',
    'tasks.empty' => 'Bu görünümde görev bulunmuyor.',
    'tasks.older' => 'Daha eski görevler',
    'tasks.updated' => 'İstenen değişiklik uygulandı.',
    'tasks.invalid' => 'Değerleri kontrol edip yeniden deneyin.',
    'tasks.denied' => 'Görev veya önbellek ayarlarını değiştirmek için yönetici olarak giriş '
        . 'yapıp şifrenizi doğrulayın.',
    'tasks.unavailable' => 'Görev veya önbellek hizmeti kullanılamıyor. Bağlantı denemesi başarısızsa '
        . 'önceki önbellek ayarı korunur.',
    'tasks.warning' => 'Uzun süredir bekleyen: {overdue, number, integer}. Süresi dolan işler: '
        . '{expired, number, integer}. Başarısız görev: {failed, number, integer}.',
    'tasks.run_result' => 'Tamamlanan: {completed, number, integer}. Devam eden: {progress, number, '
        . 'integer}. Başarısız: {failed, number, integer}.',
    'tasks.status_pending' => 'Bekliyor',
    'tasks.status_running' => 'Çalışıyor',
    'tasks.status_failed' => 'Başarısız',
    'tasks.status_completed' => 'Tamamlandı',
    'tasks.unknown' => 'Eklenti görevi',
    'tasks.job_post' => 'Tartışma bildirimleri',
    'tasks.job_export' => 'Kişisel veri paketi',
    'tasks.job_delete' => 'Hesap silme',
    'tasks.job_mail' => 'Bildirim e-postası',
    'tasks.details' => 'Teknik ayrıntılar',
    'tasks.count_capped' => 'Özet sayıları her kategori için en fazla 1000 gösterilir.',
];
