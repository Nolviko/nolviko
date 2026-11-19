<?php

declare(strict_types=1);

return [
    'operations.imports' => 'Eski forumdan aktarım',
    'operations.diagnostics' => 'Sistem tanılama',
    'operations.notify_policy' => 'Güncellemeler yönetici onayı gerektirir. Otomatik kurulum kapalıdır.',
    'operations.setup' => 'İmzalı güncelleme hazırlamak için sunucu sahibi güvenilir yayın '
        . 'anahtarlarını tanımlamalıdır.',
    'operations.check' => 'Sürümleri kontrol et',
    'operations.stage' => 'Doğrulanmış sürümü hazırla',
    'operations.abandon' => 'Bu hazırlığı iptal et',
    'operations.abandoned' => 'Hazırlık iptal edildi.',
    'operations.archive' => 'Sürüm ZIP dosyası',
    'operations.manifest' => 'İmzalı bildirim',
    'operations.signature' => 'İmza dosyası',
    'operations.confirm' => 'Bu sürümün hazırlanmasını onaylıyorum. Kurulum için doğrulanmış yedek gereklidir.',
    'operations.upload' => 'Yüklenen sürümü doğrula',
    'operations.uploaded' => 'Sürüm doğrulandı. Hazırlamaya devam edin.',
    'operations.download_heading' => 'En yeni resmi sürümü kur',
    'operations.download_help' => 'En yeni sürümü GitHub\'dan indirir ve hiçbir şey hazırlanmadan önce imzasını '
        . 'config/update-trust.json içindeki Nolviko anahtarıyla doğrular. Dosyalar değişmeden önce yedek alınır.',
    'operations.download' => 'İndir ve doğrula',
    'operations.downloading' => 'İndiriliyor ve doğrulanıyor…',
    'operations.manual_upload' => 'Ya da sürümü elle yükleyin',
    'operations.download_failed' => 'İmzalı sürüm indirilemedi. Hiçbir şey değişmedi; daha sonra tekrar deneyin '
        . 'ya da elle yükleyin.',
    'operations.staging' => 'Hazırlık kaydedildi. Kurtarma hazır olana kadar devam edin.',
    'operations.denied' => 'Yönetici olarak yeniden doğrulama yapıp güvenli bağlantıdan gönderin.',
    'operations.busy' => 'Başka bir iş veya aktarım etkin. Güncellemeden önce tamamlayın.',
    'operations.addons' => 'Kurulu bir eklenti veya tema uyumsuz.',
    'operations.files' => 'Kurulu dosyalar değişmiş veya güvenle değiştirilemiyor.',
    'operations.problems_heading' => 'Güncelleme henüz başlatılamıyor:',
    'operations.integrity' => 'Bazı Nolviko dosyaları kurulu sürümden farklı veya eksik (elle düzenleme ya da yarım '
        . 'kalmış bir yükleme). Kurulu sürümünüzün ZIP\'ini forum klasörünün üzerine yeniden yükleyip tekrar deneyin.',
    'operations.writable' => 'PHP forum klasörüne, config/ veya storage/ klasörüne yazamıyor. Web sunucusu '
        . 'kullanıcısına yazma izni verin (barındırma firmanız yapabilir) ve tekrar deneyin.',
    'operations.private_storage' => 'storage/ web\'den erişilememeli: public/ içinde olmamalı, bir bağlantı (link) '
        . 'olmamalı ve .htaccess dosyası (Require all denied) yerinde durmalı.',
    'operations.storage_permissions' => 'storage/ güncellemeler için kullanılamıyor: bir bağlantı (link) olmamalı, '
        . 'public/ içinde olmamalı ve PHP içinde klasör oluşturabilmeli. Barındırma firmanızdan bunu kontrol etmesini '
        . 'isteyin ve tekrar deneyin.',
    'operations.replayed' => 'Bu sürüm, kurulu olarak kayıtlı sürümden daha yeni değil.',
    'operations.clock' => 'Sunucu saati sürüm imzasının geçerlilik aralığının dışında. Barındırma firmanızdan '
        . 'sunucu saatini düzeltmesini isteyin ve tekrar deneyin.',
    'operations.problem_codes' => 'Teknik kod: {codes}',
    'operations.update_now' => 'Şimdi güncelle',
    'operations.password_title' => 'Parolanızla onaylayın',
    'operations.password_help' => 'Güncelleme tüm forumu değiştirir, bu yüzden bir kez parolanız gerekir.',
    'operations.password_code' => 'Doğrulama uygulamanızdaki kod',
    'operations.password_confirm' => 'Onayla ve güncelle',
    'operations.password_cancel' => 'Vazgeç',
    'operations.continue_update' => 'Güncellemeye devam et',
    'operations.one_click_help' => 'Forum en yeni sürümü indirip kontrol eder, veritabanını yedekler, siteyi kısa bir '
        . 'süre kapatır, dosyaları değiştirir ve yeniden açar. Bitti diyene kadar bu sayfayı açık tutun.',
    'operations.manual_steps' => 'Adım adım (JavaScript olmadan)',
    'operations.progress_download' => 'Sürüm indiriliyor ve kontrol ediliyor…',
    'operations.progress_stage' => 'Yeni dosyalar hazırlanıyor',
    'operations.progress_copy' => 'Güvenlik kopyası hazırlanıyor…',
    'operations.progress_backup' => 'Veritabanı yedekleniyor',
    'operations.progress_switch' => 'Dosyalar değiştiriliyor',
    'operations.progress_migrate' => 'Veritabanı güncelleniyor…',
    'operations.progress_check' => 'Yeni sürüm kontrol ediliyor…',
    'operations.progress_finish' => 'Bitiriliyor…',
    'operations.progress_retry' => 'Bağlantı koptu. Güncelleme kontrol ediliyor ve yeniden deneniyor…',
    'operations.progress_cancelled' => 'Güncelleme başlatılmadı.',
    'operations.progress_done' => 'Güncelleme tamamlandı. Sayfa yenileniyor…',
    'operations.progress_failed' => 'Güncelleme durdu. Hiçbir şey kaybolmadı: devam etmek için "Güncellemeye devam et" '
        . 'düğmesine basın.',
    'operations.code_fallback' => 'Güncelleme sırasında bu sayfa kapanırsa',
    'operations.code_fallback_help' => 'Kurtarma sayfasını açıp bu kodu girerek devam edin (son adımdan sonra 15 '
        . 'dakika geçerli).',
    'operations.backup_unavailable' => 'Bu veritabanı için otomatik yedek alınamıyor, bu yüzden güncelleme '
        . 'başlatılmadı.',
    'operations.channel' => 'Bu sürüm başka bir güncelleme kanalına ait (beta ya da kararlı).',
    'operations.disk' => 'Güvenli güncelleme için yeterli boş alan yok.',
    'operations.platform' => 'Sunucu bu sürümün gereksinimlerini karşılamıyor.',
    'operations.schema' => 'Önce bekleyen veritabanı geçişlerini tamamlayın.',
    'operations.manual_baseline' => 'Güncelleyiciyle uyumlu ilk sürümü elle kurun.',
    'operations.not_newer' => 'Bu sürüm kurulu sürümden daha yeni değil.',
    'operations.confirm_required' => 'Açık onay gerekiyor.',
    'operations.no_release' => 'Uygun yeni sürüm bulunamadı.',
    'operations.upload_failed' => 'Yükleme doğrulanamadı. Üç özgün sürüm dosyasını kontrol edin.',
    'operations.refused' => 'İşlem güvenle reddedildi. Yayın güvenini ve ön koşulları kontrol edin.',
    'operations.release_hint' => 'İncelenebilecek yeni bir sürüm var.',
    'operations.private_archive' => 'Aktarılan özel arşiv. Yalnızca kendi kopyanız gösterilir; yanıt verilemez.',
    'operations.poll_locked' => 'Aktarılan bu anket yalnızca okunabilir.',
    'operations.poll_limit' => '{count, plural, one {En fazla # seçenek seçin.} other {En fazla # seçenek seçin.}}',
    'operations.poll_results_hidden' => 'Sonuçlar özgün anket kuralları nedeniyle gizli.',
    'operations.all_files' => 'Ekli dosyalar',
    'operations.more_files' => 'Diğer dosyalar',
    'operations.no_files' => 'Erişilebilir dosya yok.',
    'operations.php' => 'PHP sürümü',
    'operations.database' => 'Veritabanı',
    'operations.storage' => 'Özel depolama',
    'operations.images' => 'Görsel işleme',
    'operations.ready' => 'Hazır',
    'operations.unavailable' => 'Kullanılamıyor',
    'operations.disabled' => 'Kapalı',
    'operations.capped' => 'En az bu kadar; özet sınırına ulaşıldı.',
    'operations.support_policy' => 'Destek dosyası yalnızca sistem sürümlerini ve sınırlı durum '
        . 'sayılarını içerir. Parola, dosya yolu, günlük ve kullanıcı içeriği içermez.',
    'operations.support_download' => 'Destek özetini indir',
    'operations.task_summary' => 'İş özeti',
    'operations.source' => 'Kaynak kopya',
    'operations.completed' => 'Aktarım doğrulandı. Erişimi açmadan önce hesapları ve izinleri inceleyin.',
    'operations.recent' => 'Son aktarımlar',
    'operations.source_key' => 'Kaynak kayıt',
    'operations.update_storage' => 'İmzalı otomatik güncelleme için uygulama klasörü dışında özel '
        . 'depolama gerekir. Varsayılan kurulumda elle güncelleme kullanılabilir.',
    'operations.published' => 'Yayında',
    'operations.importer_intro' => 'SMF, MyBB ve phpBB\'den aktarım resmi Aktarıcı eklentisiyle yapılır. Yönetim > '
        . 'Eklentiler\'den kurun, eski forumunuzu aktarın, iş bitince kaldırın: aktarılan içerik, eski adresler ve '
        . 'parolalar çalışmaya devam eder.',
    'operations.importer_install' => 'Yönetim > Eklentiler\'i aç',
    'operations.importer_unfinished' => 'bitmedi; sürdürmek için Aktarıcı eklentisini kurun',
];
