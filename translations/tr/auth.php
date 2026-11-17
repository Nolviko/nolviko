<?php

declare(strict_types=1);

return [
    'auth.login.title' => 'Giriş yap',
    'auth.login.identifier' => 'Kullanıcı adı veya e-posta adresi',
    'auth.login.password' => 'Parola',
    'auth.login.remember' => 'Bu cihazda oturumumu açık tut',
    'auth.login.submit' => 'Giriş yap',
    'auth.login.register' => 'Hesabınız yok mu? Kaydolun',
    'auth.login.resend' => 'Doğrulama e-postası gelmedi mi?',
    'auth.register.title' => 'Kaydol',
    'auth.register.username' => 'Kullanıcı adı (iletilerinizde görünür)',
    'auth.register.email' => 'E-posta adresi',
    'auth.register.password' => 'Parola (en az {min, number, integer} karakter)',
    'auth.register.confirmation' => 'Parolayı tekrarlayın',
    'auth.register.submit' => 'Hesap oluştur',
    'auth.register.have_account' => 'Hesabınız var mı? Giriş yapın',
    'auth.register.sent_title' => 'Gelen kutunuzu kontrol edin',
    'auth.register.sent' => 'Girdiğiniz adrese bir e-posta gönderdik. Hesabınızı doğrulamak '
        . 'için 24 saat içinde iletideki bağlantıyı açın. Adres zaten bir '
        . 'hesaba aitse e-posta giriş yapma bilgilerini içerir.',
    'auth.register.no_mail' => 'Birkaç dakika sonra hâlâ e-posta yok mu? Yeni bağlantı isteyin',
    'auth.register.to_login' => 'Giriş sayfasına git',
    'auth.verify.title' => 'E-posta adresinizi doğrulayın',
    'auth.verify.confirm_intro' => 'Hesabınızı etkinleştirmek için e-posta adresinizi doğrulayın.',
    'auth.verify.confirm' => 'E-posta adresimi doğrula',
    'auth.verify.done' => 'Teşekkürler. E-posta adresiniz doğrulandı; artık giriş yapabilirsiniz.',
    'auth.verify.invalid' => 'Bu doğrulama bağlantısı geçerli değil. Süresi dolmuş veya daha '
        . 'önce kullanılmış olabilir.',
    'auth.verify.request_new' => 'Yeni doğrulama bağlantısı iste',
    'auth.resend.title' => 'Yeni doğrulama bağlantısı',
    'auth.resend.intro' => 'Kayıt olduğunuz adresi girin. Adrese ait doğrulanmamış bir hesap '
        . 'varsa yeni bağlantı göndeririz; bağlantılar 24 saat geçerlidir.',
    'auth.resend.submit' => 'Yeni bağlantı gönder',
    'auth.register.website' => 'Bu alanı boş bırakın',
    'auth.register.sent_open' => 'Bu adres yeniyse hesabınız hazır; artık giriş yapabilirsiniz. '
        . 'Adres zaten bir hesaba aitse o adrese e-posta gönderdik.',
    'auth.register.closed_title' => 'Kayıt kapalı',
    'auth.register.closed' => 'Bu forum şu anda yeni hesap kabul etmiyor.',
    'auth.challenge.sum' => 'İnsan olduğunuzu göstermek için yanıtlayın: {first, number, '
        . 'integer} artı {second, number, integer} kaç eder?',
    'auth.verify.done_approval' => 'Teşekkürler. E-posta adresiniz doğrulandı. Yeni hesaplar yönetici'
        . ' tarafından incelenir; hesabınız onaylanınca giriş '
        . 'yapabilirsiniz.',
    'auth.login.forgot' => 'Parolanızı mı unuttunuz?',
    'auth.forgot.title' => 'Parolamı unuttum',
    'auth.forgot.intro' => 'Hesabınızın adresini girin. Adrese ait etkin bir hesap varsa yeni'
        . ' parola seçmeniz için bağlantı göndeririz; bağlantı bir saat '
        . 'geçerlidir.',
    'auth.forgot.submit' => 'Bağlantıyı gönder',
    'auth.forgot.sent' => 'Adrese ait etkin bir hesap varsa bağlantı gönderiliyor.',
    'auth.reset.title' => 'Yeni parola seçin',
    'auth.reset.submit' => 'Yeni parolayı kaydet',
    'auth.reset.done' => 'Parolanız değiştirildi. Tüm cihazlardaki oturumlarınız kapatıldı;'
        . ' yeni parolanızla giriş yapın.',
    'auth.reset.invalid' => 'Bu bağlantı geçerli değil. Süresi dolmuş veya daha önce kullanılmış olabilir.',
    'auth.reset.request_new' => 'Yeni bağlantı iste',
    'auth.account.title' => 'Hesabınız',
    'auth.account.signed_in_as' => '{name} ({email}) olarak giriş yaptınız.',
    'auth.account.current_password' => 'Mevcut parola',
    'auth.account.password_heading' => 'Parolanızı değiştirin',
    'auth.account.password_submit' => 'Parolayı değiştir',
    'auth.account.password_changed' => 'Parolanız değiştirildi. Diğer tüm cihazlardaki oturumlarınız kapatıldı.',
    'auth.account.email_heading' => 'E-posta adresinizi değiştirin',
    'auth.account.preferences_heading' => 'Dil ve saat dilimi',
    'auth.account.language' => 'Dil',
    'auth.account.language_default' => 'Site varsayılanı',
    'auth.account.time_zone' => 'Saat dilimi',
    'auth.account.time_zone_default' => 'Site varsayılanı',
    'auth.account.preferences_submit' => 'Tercihleri kaydet',
    'auth.account.preferences_saved' => 'Tercihleriniz kaydedildi.',
    'auth.account.preferences_invalid' => 'Dil ve saat dilimini listelerden seçin.',
    'auth.account.new_email' => 'Yeni e-posta adresi',
    'auth.account.email_submit' => 'Doğrulama bağlantısı gönder',
    'auth.account.email_requested' => 'Yeni adres kullanılabiliyorsa değişikliği doğrulama bağlantısı o '
        . 'adrese gönderildi. O zamana kadar eski adresiniz geçerlidir.',
    'auth.email_confirm.title' => 'Yeni e-posta adresinizi doğrulayın',
    'auth.email_confirm.intro' => 'Bu adresi hesabınızda kullanmak için doğrulayın.',
    'auth.email_confirm.submit' => 'Bu adresi kullan',
    'auth.email_confirm.done' => 'Hesabınız artık yeni e-posta adresini kullanıyor.',
    'auth.email_confirm.invalid' => 'Bu bağlantı geçerli değil. Süresi dolmuş, daha önce kullanılmış '
        . 'veya adres artık kullanımda olabilir.',
    'auth.resend.sent' => 'Adrese ait doğrulanmamış bir hesap varsa yeni bağlantı gönderiliyor.',
    'auth.privacy.title' => 'Gizlilik ve hesap silme',
    'auth.privacy.policy' => 'Yeni silme isteklerinde {days, number} gün iptal süresi vardır. Süre dolunca sonraki '
        . 'site ziyaretlerinden biri işlemi başlatır; ziyaret yoksa bekler. Siz veya yönetici '
        . 'elle de tamamlayabilir. Hesap kaldırılır, forum içerikleri silinmiş hesap adına bağlı '
        . 'kalır. İleti metinleri, alıntılar, güvenlik kayıtları ve yedekler otomatik silinmez.',
    'auth.privacy.scope' => 'İndirme; profilinizi, başlattığınız konuları, iletilerinizi ve katıldığınız özel '
        . 'konuşmaları içerir. Diğer katılımcıların e-posta adresleri ve ilgisiz konuşmalar '
        . 'eklenmez. Büyük hesaplar için yönetici yardımı gerekir. Silmeyi tamamlamadan önce '
        . 'verilerinizi indirin.',
    'auth.privacy.admin_notice' => 'Yönetici işlemleri yakın zamanda parola doğrulaması gerektirir ve'
        . ' günlüğe kaydedilir. Yönetici indirmelerine özel konuşmalar '
        . 'eklenmez; tam arşivi hesap sahibi kendi hesabından indirmelidir. '
        . 'Hesabı dikkatle seçin; tamamlanan silme geri alınamaz.',
    'auth.privacy.target' => 'Kullanıcı adı',
    'auth.privacy.select' => 'Hesabı seç',
    'auth.privacy.selected' => 'Seçilen hesap: {id, number, integer}',
    'auth.privacy.pending' => 'Silme {date} UTC tarihinden itibaren tamamlanabilir. Bu zamandan önce iptal '
        . 'edebilirsiniz.',
    'auth.privacy.confirm' => 'İçerik saklama politikasını anladım; silme isteğini veya silmenin tamamlanmasını '
        . 'onaylıyorum.',
    'auth.privacy.export' => 'Verileri indir (ZIP)',
    'auth.privacy.request' => 'Silme isteği oluştur',
    'auth.privacy.cancel' => 'Silme isteğini iptal et',
    'auth.privacy.finalize' => 'Süre sonunda silmeyi tamamla',
    'auth.privacy.updated' => 'Hesap gizlilik isteği güncellendi.',
    'auth.privacy.due' => 'Tamamlama onayı bekleyen silme istekleri',
    'auth.privacy.due_item' => 'Hesap {id, number, integer}: {date} UTC tarihinden beri hazır',
    'auth.privacy.none_due' => 'Tamamlanmaya hazır silme isteği yok.',
    'auth.privacy.pending_auto' => 'Otomatik silme {date} UTC tarihi veya sonraki bir site ziyareti için kuyruğa '
        . 'alındı. Bu zamandan önce iptal edebilirsiniz.',
    'auth.privacy.processing' => 'Silme kalıcı parçalar halinde sürüyor. Giriş kapatıldı; iptal süresi dolduktan '
        . 'sonra bu işlem iptal edilemez.',
    'auth.privacy.export_pending' => 'Arşiviniz kuyruğa alındı. Site ziyaretleriyle kaldığı yerden parça parça '
        . 'hazırlanır. İlerlemeyi görmek için sayfayı yenileyin; eksik arşiv indirilemez.',
    'auth.privacy.export_help' => 'Dışa aktarma düğmesi arşivi hazırlar veya indirir. Bu sayfa açık kalmadan da '
        . 'hazırlık sürer. Hazır olduğunda indirmek için mevcut parolanızı yeniden girin. '
        . 'Veriler farklı isteklerde toplandığından bu sırada değiştirilen kayıtlar farklı '
        . 'toplama anlarını yansıtabilir. Geçici arşivlerin süresi yedi günde dolar; sonraki'
        . ' işlemlerde temizlenir.',
    'auth.privacy.export_status' => '{status, select, ready {Arşiv indirilmeye hazır.} failed {Hazırlık durdu; '
        . 'yeniden denemek için dışa aktarma düğmesini kullanın veya yöneticiden yardım '
        . 'isteyin.} other {Arşiv hazırlanıyor.}} Toplanan kayıt: {count, number}.',
    'auth.account.session_heading' => 'Oturum',
    'auth.account.logout' => 'Çıkış yap',
    'auth.account.logout_everywhere' => 'Tüm cihazlarda çıkış yap',
    'auth.privacy.target_unknown' => 'Bu kullanıcı adıyla bir hesap yok.',
    'auth.privacy.selected_name' => 'Seçilen hesap: {name} (numara {id, number, integer})',
    'auth.register.work_status' => 'Bot olmadığınız denetleniyor… birkaç saniye sürer.',
    'auth.logout.title' => 'Çıkış yap',
    'auth.logout.body' => 'Bu cihazda hesabınızdan çıkış yapılsın mı?',
    'auth.logout.cancel' => 'Oturumda kal',
    'auth.login_verify.title' => 'İki adımlı doğrulama',
    'auth.login_verify.intro' => 'Girişi tamamlamak için doğrulama uygulamanızdaki kodu girin.',
    'auth.login_verify.code' => 'Kod',
    'auth.login_verify.recovery_hint' => 'Telefonunuza erişemiyor musunuz? Kurtarma kodlarınızdan birini girin.',
    'auth.login_verify.submit' => 'Doğrula',
    'auth.login_verify.cancel' => 'Vazgeç',
    'auth.account.second_factor_heading' => 'İki adımlı doğrulama',
    'auth.account.second_factor_on' => 'Açık',
    'auth.account.second_factor_off' => 'Kapalı',
    'auth.external.or' => 'ya da',
    'auth.external.title' => 'Başka bir hesapla giriş',
    'auth.external.back_to_login' => 'Girişe dön',
    'auth.external.register_title' => 'Hesabınızı oluşturun',
    'auth.external.register_intro' => 'Hesabınız {email} adresini kullanacak. Diğer üyelerin göreceği adı seçin.',
    'auth.external.username' => 'Kullanıcı adı',
    'auth.external.register_submit' => 'Hesabı oluştur',
    'auth.external.accounts_heading' => 'Bağlı hesaplar',
    'auth.external.linked' => 'Bağlı',
    'auth.external.link' => '{provider} bağla',
    'auth.external.unlink' => 'Bağlantıyı kaldır',
    'auth.external.unlink_help' => 'Bağlantıyı kaldırmak için parolanız gerekir. Hesabınızı başka bir hesapla mı '
        . 'açtınız? Önce "Parolamı unuttum" ile parola belirleyin.',
    'auth.external.link_title' => 'Hesabınıza bağlayın',
    'auth.external.link_intro' => '{email} adresiyle zaten bir hesap var. Google hesabınızı bağlamak için bir kez o '
        . 'hesabın parolasını girin; sonraki girişlerde yalnızca Google yeterli olur.',
    'auth.external.link_submit' => 'Bağla ve giriş yap',
    'auth.external.link_forgot' => 'Parolanızı mı unuttunuz? Yenisini belirleyin, sonra tekrar Google ile giriş yapın.',
];
