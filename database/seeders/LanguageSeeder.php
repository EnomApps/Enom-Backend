<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['code'=>'en','name'=>'English','native_name'=>'English','flag'=>'🌐','region'=>null,'is_rtl'=>false,'sort_order'=>1],
            ['code'=>'as','name'=>'Assamese','native_name'=>'অসমীয়া','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>2],
            ['code'=>'bn','name'=>'Bengali','native_name'=>'বাংলা','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>3],
            ['code'=>'brx','name'=>'Bodo','native_name'=>'बड़ो','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>4],
            ['code'=>'doi','name'=>'Dogri','native_name'=>'डोगरी','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>5],
            ['code'=>'gu','name'=>'Gujarati','native_name'=>'ગુજરાતી','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>6],
            ['code'=>'hi','name'=>'Hindi','native_name'=>'हिन्दी','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>7],
            ['code'=>'kn','name'=>'Kannada','native_name'=>'ಕನ್ನಡ','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>8],
            ['code'=>'ks','name'=>'Kashmiri','native_name'=>'कॉशुर','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>9],
            ['code'=>'kok','name'=>'Konkani','native_name'=>'कोंकणी','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>10],
            ['code'=>'mai','name'=>'Maithili','native_name'=>'मैथिली','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>11],
            ['code'=>'ml','name'=>'Malayalam','native_name'=>'മലയാളം','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>12],
            ['code'=>'mni','name'=>'Manipuri','native_name'=>'মণিপুরী','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>13],
            ['code'=>'mr','name'=>'Marathi','native_name'=>'मराठी','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>14],
            ['code'=>'ne','name'=>'Nepali','native_name'=>'नेपाली','flag'=>'🇳🇵','region'=>'Indian','is_rtl'=>false,'sort_order'=>15],
            ['code'=>'or','name'=>'Odia','native_name'=>'ଓଡ଼ିଆ','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>16],
            ['code'=>'pa','name'=>'Punjabi','native_name'=>'ਪੰਜਾਬੀ','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>17],
            ['code'=>'sa','name'=>'Sanskrit','native_name'=>'संस्कृतम्','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>18],
            ['code'=>'sat','name'=>'Santali','native_name'=>'ᱥᱟᱱᱛᱟᱲᱤ','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>19],
            ['code'=>'sd','name'=>'Sindhi','native_name'=>'سنڌي','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>true,'sort_order'=>20],
            ['code'=>'ta','name'=>'Tamil','native_name'=>'தமிழ்','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>21],
            ['code'=>'te','name'=>'Telugu','native_name'=>'తెలుగు','flag'=>'🇮🇳','region'=>'Indian','is_rtl'=>false,'sort_order'=>22],
            ['code'=>'ur','name'=>'Urdu','native_name'=>'اردو','flag'=>'🇵🇰','region'=>'South Asian','is_rtl'=>true,'sort_order'=>23],
            ['code'=>'ar','name'=>'Arabic','native_name'=>'العربية','flag'=>'🇸🇦','region'=>'Middle East','is_rtl'=>true,'sort_order'=>24],
            ['code'=>'fa','name'=>'Persian','native_name'=>'فارسی','flag'=>'🇮🇷','region'=>'Middle East','is_rtl'=>true,'sort_order'=>25],
            ['code'=>'he','name'=>'Hebrew','native_name'=>'עברית','flag'=>'🇮🇱','region'=>'Middle East','is_rtl'=>true,'sort_order'=>26],
            ['code'=>'sw','name'=>'Swahili','native_name'=>'Kiswahili','flag'=>'🇰🇪','region'=>'African','is_rtl'=>false,'sort_order'=>27],
            ['code'=>'ha','name'=>'Hausa','native_name'=>'Hausa','flag'=>'🇳🇬','region'=>'African','is_rtl'=>false,'sort_order'=>28],
            ['code'=>'am','name'=>'Amharic','native_name'=>'አማርኛ','flag'=>'🇪🇹','region'=>'African','is_rtl'=>false,'sort_order'=>29],
            ['code'=>'yo','name'=>'Yoruba','native_name'=>'Yorùbá','flag'=>'🇳🇬','region'=>'African','is_rtl'=>false,'sort_order'=>30],
            ['code'=>'ig','name'=>'Igbo','native_name'=>'Igbo','flag'=>'🇳🇬','region'=>'African','is_rtl'=>false,'sort_order'=>31],
            ['code'=>'om','name'=>'Oromo','native_name'=>'Afaan Oromoo','flag'=>'🇪🇹','region'=>'African','is_rtl'=>false,'sort_order'=>32],
            ['code'=>'zu','name'=>'Zulu','native_name'=>'isiZulu','flag'=>'🇿🇦','region'=>'African','is_rtl'=>false,'sort_order'=>33],
            ['code'=>'tr','name'=>'Turkish','native_name'=>'Türkçe','flag'=>'🇹🇷','region'=>'Middle East','is_rtl'=>false,'sort_order'=>34],
            ['code'=>'prs','name'=>'Dari','native_name'=>'دری','flag'=>'🇦🇫','region'=>'Central Asian','is_rtl'=>true,'sort_order'=>35],
            ['code'=>'ps','name'=>'Pashto','native_name'=>'پښتو','flag'=>'🇦🇫','region'=>'Central Asian','is_rtl'=>true,'sort_order'=>36],
            ['code'=>'ms','name'=>'Malay','native_name'=>'Bahasa Melayu','flag'=>'🇲🇾','region'=>'SE Asian','is_rtl'=>false,'sort_order'=>37],
            ['code'=>'id','name'=>'Indonesian','native_name'=>'Bahasa Indonesia','flag'=>'🇮🇩','region'=>'SE Asian','is_rtl'=>false,'sort_order'=>38],
            ['code'=>'th','name'=>'Thai','native_name'=>'ไทย','flag'=>'🇹🇭','region'=>'SE Asian','is_rtl'=>false,'sort_order'=>39],
            ['code'=>'vi','name'=>'Vietnamese','native_name'=>'Tiếng Việt','flag'=>'🇻🇳','region'=>'SE Asian','is_rtl'=>false,'sort_order'=>40],
            ['code'=>'fil','name'=>'Filipino','native_name'=>'Filipino','flag'=>'🇵🇭','region'=>'SE Asian','is_rtl'=>false,'sort_order'=>41],
            ['code'=>'tl','name'=>'Tagalog','native_name'=>'Tagalog','flag'=>'🇵🇭','region'=>'SE Asian','is_rtl'=>false,'sort_order'=>42],
            ['code'=>'my','name'=>'Burmese','native_name'=>'ဗမာစာ','flag'=>'🇲🇲','region'=>'SE Asian','is_rtl'=>false,'sort_order'=>43],
            ['code'=>'km','name'=>'Khmer','native_name'=>'ខ្មែរ','flag'=>'🇰🇭','region'=>'SE Asian','is_rtl'=>false,'sort_order'=>44],
            ['code'=>'lo','name'=>'Lao','native_name'=>'ລາວ','flag'=>'🇱🇦','region'=>'SE Asian','is_rtl'=>false,'sort_order'=>45],
            ['code'=>'tet','name'=>'Tetum','native_name'=>'Tetun','flag'=>'🇹🇱','region'=>'SE Asian','is_rtl'=>false,'sort_order'=>46],
            ['code'=>'si','name'=>'Sinhalese','native_name'=>'සිංහල','flag'=>'🇱🇰','region'=>'South Asian','is_rtl'=>false,'sort_order'=>47],
            ['code'=>'ja','name'=>'Japanese','native_name'=>'日本語','flag'=>'🇯🇵','region'=>'East Asian','is_rtl'=>false,'sort_order'=>48],
            ['code'=>'ko','name'=>'Korean','native_name'=>'한국어','flag'=>'🇰🇷','region'=>'East Asian','is_rtl'=>false,'sort_order'=>49],
            ['code'=>'zh','name'=>'Chinese','native_name'=>'中文','flag'=>'🇨🇳','region'=>'East Asian','is_rtl'=>false,'sort_order'=>50],
            ['code'=>'sm','name'=>'Samoan','native_name'=>'Gagana Sāmoa','flag'=>'🇼🇸','region'=>'Pacific','is_rtl'=>false,'sort_order'=>51],
            ['code'=>'to','name'=>'Tongan','native_name'=>'Lea Fakatonga','flag'=>'🇹🇴','region'=>'Pacific','is_rtl'=>false,'sort_order'=>52],
            ['code'=>'mi','name'=>'Maori','native_name'=>'Te Reo Māori','flag'=>'🇳🇿','region'=>'Pacific','is_rtl'=>false,'sort_order'=>53],
            ['code'=>'haw','name'=>'Hawaiian','native_name'=>'ʻŌlelo Hawaiʻi','flag'=>'🇺🇸','region'=>'Pacific','is_rtl'=>false,'sort_order'=>54],
            ['code'=>'ty','name'=>'Tahitian','native_name'=>'Reo Tahiti','flag'=>'🇵🇫','region'=>'Pacific','is_rtl'=>false,'sort_order'=>55],
            ['code'=>'es','name'=>'Spanish','native_name'=>'Español','flag'=>'🇪🇸','region'=>'European','is_rtl'=>false,'sort_order'=>56],
            ['code'=>'fr','name'=>'French','native_name'=>'Français','flag'=>'🇫🇷','region'=>'European','is_rtl'=>false,'sort_order'=>57],
            ['code'=>'de','name'=>'German','native_name'=>'Deutsch','flag'=>'🇩🇪','region'=>'European','is_rtl'=>false,'sort_order'=>58],
            ['code'=>'it','name'=>'Italian','native_name'=>'Italiano','flag'=>'🇮🇹','region'=>'European','is_rtl'=>false,'sort_order'=>59],
            ['code'=>'pt','name'=>'Portuguese','native_name'=>'Português','flag'=>'🇵🇹','region'=>'European','is_rtl'=>false,'sort_order'=>60],
            ['code'=>'ru','name'=>'Russian','native_name'=>'Русский','flag'=>'🇷🇺','region'=>'European','is_rtl'=>false,'sort_order'=>61],
            ['code'=>'uk','name'=>'Ukrainian','native_name'=>'Українська','flag'=>'🇺🇦','region'=>'European','is_rtl'=>false,'sort_order'=>62],
            ['code'=>'nl','name'=>'Dutch','native_name'=>'Nederlands','flag'=>'🇳🇱','region'=>'European','is_rtl'=>false,'sort_order'=>63],
            ['code'=>'pl','name'=>'Polish','native_name'=>'Polski','flag'=>'🇵🇱','region'=>'European','is_rtl'=>false,'sort_order'=>64],
            ['code'=>'cs','name'=>'Czech','native_name'=>'Čeština','flag'=>'🇨🇿','region'=>'European','is_rtl'=>false,'sort_order'=>65],
            ['code'=>'ro','name'=>'Romanian','native_name'=>'Română','flag'=>'🇷🇴','region'=>'European','is_rtl'=>false,'sort_order'=>66],
            ['code'=>'hu','name'=>'Hungarian','native_name'=>'Magyar','flag'=>'🇭🇺','region'=>'European','is_rtl'=>false,'sort_order'=>67],
            ['code'=>'el','name'=>'Greek','native_name'=>'Ελληνικά','flag'=>'🇬🇷','region'=>'European','is_rtl'=>false,'sort_order'=>68],
            ['code'=>'sv','name'=>'Swedish','native_name'=>'Svenska','flag'=>'🇸🇪','region'=>'European','is_rtl'=>false,'sort_order'=>69],
            ['code'=>'no','name'=>'Norwegian','native_name'=>'Norsk','flag'=>'🇳🇴','region'=>'European','is_rtl'=>false,'sort_order'=>70],
            ['code'=>'da','name'=>'Danish','native_name'=>'Dansk','flag'=>'🇩🇰','region'=>'European','is_rtl'=>false,'sort_order'=>71],
            ['code'=>'fi','name'=>'Finnish','native_name'=>'Suomi','flag'=>'🇫🇮','region'=>'European','is_rtl'=>false,'sort_order'=>72],
            ['code'=>'sk','name'=>'Slovak','native_name'=>'Slovenčina','flag'=>'🇸🇰','region'=>'European','is_rtl'=>false,'sort_order'=>73],
            ['code'=>'sq','name'=>'Albanian','native_name'=>'Shqip','flag'=>'🇦🇱','region'=>'European','is_rtl'=>false,'sort_order'=>74],
            ['code'=>'sr','name'=>'Serbian','native_name'=>'Српски','flag'=>'🇷🇸','region'=>'European','is_rtl'=>false,'sort_order'=>75],
            ['code'=>'et','name'=>'Estonian','native_name'=>'Eesti','flag'=>'🇪🇪','region'=>'European','is_rtl'=>false,'sort_order'=>76],
            ['code'=>'bg','name'=>'Bulgarian','native_name'=>'Български','flag'=>'🇧🇬','region'=>'European','is_rtl'=>false,'sort_order'=>77],
            ['code'=>'be','name'=>'Belarusian','native_name'=>'Беларуская','flag'=>'🇧🇾','region'=>'European','is_rtl'=>false,'sort_order'=>78],
        ];

        foreach ($languages as $lang) {
            Language::firstOrCreate(['code' => $lang['code']], $lang);
        }
    }
}
