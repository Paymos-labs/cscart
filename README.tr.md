# CS-Cart için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

CS-Cart, Multi-Vendor ve Ultimate için resmî Paymos eklentisi. Paymos adında bir ödeme işlemcisi
kaydeder: müşteri siparişi verir, Paymos’un barındırılan ödeme sayfasına yönlendirilir ve kendi
cüzdanından bir stablecoin ile ödeme yapar. Transfer zincir üzerinde onaylandığında sipariş durumu
imzalı bir webhook ile güncellenir.

[![İzleyin: 12 Resmî CMS Kripto Ödeme Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video anlatım (1:12, 6 dilde altyazılı):** [YouTube’da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

- 4.x serisinde CS-Cart, Multi-Vendor veya Ultimate. `addon.xml` eklenti şeması olarak `3.0`
  tanımlar ve Multi-Vendor ile Ultimate sürümlerinde otomatik kurulur
- `curl`, `hash`, `json` ve `openssl` uzantıları etkin PHP 7.4 veya üzeri — paketle gelen Paymos
  PHP SDK’nın tanımladığı alt sınır
- HTTPS üzerinden çalışan bir vitrin; bağlantı akışı bunun dışındakileri reddeder
- Saklanan kimlik bilgileri bu anahtardan türetildiği için yapılandırılmış CS-Cart `crypt_key` ayarı
- Vitrinin faturalandıracağı projenin panelde seçili olduğu bir Paymos hesabı

## Kurulum

[Releases](https://github.com/Paymos-labs/cscart/releases/latest) sayfasından veya Paymos
panelindeki **CMS integration** bölümünden `paymos-cscart-<version>.zip` paketini alın ve
**Add-ons → Manage add-ons → + → Local** üzerinden yükleyin.

Arşivden üç dizin ağacı çıkar ve üçü de gereklidir:

- `app/addons/paymos` — Paymos PHP SDK içine gömülü olan eklentinin kendisi
- `design/backend/…` — işlemci ayarları şablonu
- `var/langs/…` — dil katalogları. CS-Cart bir eklentinin `.po` dosyalarını hiçbir zaman eklenti
  içinden değil, her zaman **mağaza kökünden** çözümler; bu nedenle `var/` dizinini kaybeden bir
  yükleme her yönetim etiketini kendi ham anahtarı olarak gösterir

Kurulum `paymos_config`, `paymos_invoices` ve `paymos_events` tablolarını oluşturur ve ödeme
işlemcileri tablosuna `Paymos` kaydını ekler. Arşiv her işyeri için aynı dosyadır; API anahtarı,
API secret’ı, proje ID’si, webhook secret’ı, OAuth token’ı veya cihaz kodu içermez.

## Bağlantı

1. Başlamadan önce Paymos panelinde doğru projenin açık olduğundan emin olun. Eklenti o projeyi
   alır ve bir daha seçim yapmanızı istemez.
2. Yönetim panelinde **Administration → Payment methods** ekranına gidin, bir ödeme yöntemi ekleyin
   veya düzenleyin ve işlemcisini **Paymos** olarak seçin.
3. İşlemci ayarlarında **Connect Paymos** düğmesine basın ve açılan sekmede vitrin URL’sini ve
   projeyi onaylayın. Tarayıcı sekmeyi engellerse onay bağlantısı ve kod düğmenin yanında görünür.
4. Ödeme yöntemini kaydedin.

Sandbox ve Live ortamlarının her ikisi de tek onayla hazırlanır. Tek etkin Payment anahtarınız
yeniden kullanılır (yoksa yeni bir tane oluşturulur) ve aşağıdaki adrese bir Invoice webhook’u
kaydedilir:

```text
https://your-store.example/index.php?dispatch=payment_notification.notify&payment=paymos&mode=webhook
```

`mode=webhook` bir süs değil adresin zorunlu parçasıdır: eklenti, bu rotada CS-Cart’ın kendi `$mode`
değeri `notify` olduğu için Paymos callback’ini sıradan bir ödeme bildirimi çağrısından ayırmak
üzere bu parametreyi okur. Mevcut bir webhook yalnızca callback URL’si, kategori ve proje tamamen
eşleştiğinde yeniden kullanılır.

Kimlik bilgileri mağazanın `crypt_key` değerinden türetilen anahtarla AES-256-GCM zarfı olarak
`paymos_config` tablosuna yazılır ve yönetim sayfasına hiçbir zaman geri yazdırılmaz.

## Sipariş durumları

Her durum, işlemci ayarlarında seçtiğiniz CS-Cart durum koduna eşlenir. Varsayılanlar yerleşik
kodlardır:

| İşlemci ayarı | Varsayılan | Ne zaman uygulanır |
|---|---|---|
| Order status while awaiting payment | `O` — Open | Fatura oluşturulup müşteri barındırılan ödeme sayfasına gönderildiğinde |
| Order status while the payment is confirming on-chain | `O` — Open | `invoice.confirming` |
| Order status when paid | `P` — Processed | `invoice.paid`, `invoice.paid_over` |
| Order status when the payment is underpaid | `F` — Failed | `invoice.underpaid` |
| Order status when the invoice is cancelled or expired | `D` — Declined | `invoice.expired`, `invoice.cancelled` |

Kendi oluşturduğunuz durumlar dahil mağazada tanımlı her kod kabul edilir. Siparişe yazılan işlem
ID’si onaylanmış transferin zincir üstü hash’idir; yükte transfer yoksa — Sandbox’ta her zaman
böyledir — Paymos fatura ID’si yazılır.

İki şey asla gerçekleşmez. Zaten yapılandırdığınız ödenmiş durumda veya işyerlerinin ödenmiş
siparişleri sıkça taşıdığı yerleşik `C` (Complete) durumunda bulunan bir sipariş, geç gelen bir
`confirming`, `underpaid`, `expired` veya `cancelled` olayıyla geriye alınmaz — olay atlanır ve
hata ayıklama günlüğü açıksa kaydedilir. Ayrıca toplamı veya para birimi artık faturayla uyuşmayan
bir sipariş hiç ödenmiş olarak işaretlenmez.

## Multi-Vendor ve Ultimate

Her iki sürüm de aynı eklentiyi alır ve eklenti ikisinde de aynı biçimde çalışır: bağlantısı
vitrine, ayarları ise ödeme yöntemine ait mağaza düzeyinde bir ödeme işlemcisi. Bir mağaza farklı
modlar veya farklı durum kodlarıyla birden fazla Paymos yöntemini yan yana çalıştırabilir.

Her ödeme bağlı Paymos projesinin işyeri bakiyesine aktarılır. Bölünmüş ödeme veya alt işyeri
hesabı yoktur; bu nedenle bir sipariş gelirinin satıcılar arasında paylaştırılması CS-Cart’ın kendi
satıcı muhasebesi içinde kalır.

## Canlıya geçmeden önce test edin

1. Ödeme yönteminde **Mode** ayarını Sandbox yapın ve vitrinden bir sipariş verin.
2. Paymos paneli Sandbox konumundayken o faturayı açın ve **Pay Full**, **Pay 50%**, **Pay 150%**
   veya **Cancel** seçeneklerini kullanın. Gerçek bir ödemenin üreteceği olayların aynısını
   gönderirler; hiçbir şey blokzincire dokunmaz.
3. Sipariş durumunu ve sipariş üzerindeki ödeme bilgileri bloğunu kontrol edin.
4. **Mode** ayarını Live yapın. Her iki kimlik bilgisi seti de tek onaydan geldiği için yeniden
   bağlanmaya veya ikinci bir kuruluma gerek yoktur.

## Webhook’lar

Teslimatlar yukarıdaki dispatch adresine `POST` istekleri olarak gelir. Güven sınırı
`X-Webhook-Signature` başlığıdır — `{timestamp}.{body}` üzerinden onaltılık HMAC-SHA256 — ve
geçersiz imza veya eski zaman damgası bir sipariş bile yüklemeden `401` döndürür. Tekrarlanan bir
`event_id` `200` yanıtı verir ve hiçbir şey yapmaz. Nihai bir olay uygulanmadan önce fatura
Merchant API’den geri okunur ve `paymos_invoices` tablosundaki anlık görüntüyle karşılaştırılır:
ortam, proje, haricî sipariş ID’si ve fatura ID’sinin tamamı uyuşmalıdır.

Fatura durum güncellemeleri için yerel bir mutabakat cron’u yoktur; bu nedenle fatura kurtarma yolu
webhook yeniden teslimatıdır. Bir döngü yaklaşık 16 saate yayılan 11 denemeden oluşur ve kısa süreli
kapalı kalan bir vitrini telafi eder. Bu süreyi aşan bir fatura olayı Paymos panelinden elle tekrar
oynatılabilir.

## İadeler

Eklenti, Paymos Merchant API (`/v1/refunds`) üzerinden iki modda yerel iade desteği sunar:

- `full` — ödenmiş fatura tutarının tamamını iade eder;
- `overpayment` — `invoice.paid_over` siparişinde yalnızca fazladan gelen tutarı iade eder.

Fonlar yalnızca Paymos’un fatura ödenirken kaydettiği doğrulanmış `first_sender` cüzdan adresine
döner. Elle alıcı cüzdan adresi girilmez ve keyfî kısmi iade tutarları desteklenmez.

CS-Cart yönetim panelinde iadeleri yönetmek için `dispatch=paymos.refunds&order_id=<order_id>`
ekranını açın (**Paymos refunds**, sipariş düzenleme izni gerektirir). Ekran iade edilebilir
tutarları ve doğrulanmış `first_sender` adresini gösterir, **Refund in full** (`full`) ve **Refund
overpayment** (`overpayment`) eylemlerini (`paymos.refund`) sunar ve sürmekte olan işlemleri kontrol
etmenizi sağlar (`paymos.refund_reconcile`). Bekleyen iadeler ayrıca imzalı webhook’lar ve
`php app/addons/paymos/cron/refunds.php` CLI cron betiğiyle mutabık kılınır; sipariş yalnızca
Paymos `completed` durumunu onayladıktan sonra güncellenir. Sandbox modu nihai iade sonuçlarının
(`completed`, `failed`, `cancelled`) simülasyonunu destekler.

## Sorun giderme

**Yönetim etiketleri `paymos.mode` gibi ham anahtarlar olarak görünüyor.** Yükleme sırasında
`var/langs` ağacı korunamamıştır. Sürüm arşivini eksiksiz olarak yeniden yükleyin; dil katalogları
eklenti dizininde değil mağaza kökünde yer alır.

**Siparişler ödeme bekliyor durumundan hiç çıkmıyor.** Kayıtlı callback adresinin hâlâ
`mode=webhook` içerdiğini doğrulayın. Bu parametre olmadan eklenti her teslimatı geçersiz callback
modu diyerek reddeder, sunucu yeniden denemeyi sürdürür ve hiçbir sipariş tamamlanmaz. Yeniden
bağlanmak doğru adresi tekrar kaydeder.

**Connect başlamayı reddediyor.** Onaylanan ve webhook’un kaydedildiği adres vitrin URL’sidir ve
`https://` olmak zorundadır. Vitrin URL’sini düzeltin, ardından yeniden bağlanın.

**Yapılandırma değişikliğinden sonra her şey bozuldu.** Kimlik bilgisi zarfı `crypt_key` ile
anahtarlanır. Bunu değiştirmek veya veritabanını farklı bir `crypt_key` kullanan bir mağazaya geri
yüklemek zarfı okunamaz hâle getirir — yenisinin düzenlenmesi için tekrar **Connect Paymos**
düğmesine basın.

## Bağlantılar

- Dokümantasyon: [paymos.io/tr/docs/cms-cscart](https://paymos.io/tr/docs/cms-cscart)
- Kaynak kodu ve sürümler: [Paymos-labs/cscart](https://github.com/Paymos-labs/cscart)
- Destek: [support@paymos.io](mailto:support@paymos.io)
