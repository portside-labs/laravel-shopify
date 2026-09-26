import i18n, { type PostProcessorModule } from 'i18next';
import { initReactI18next } from 'react-i18next';

/**
 * The app's language files for one locale, keyed by file and then by key.
 */
export type Translations = { [key: string]: string | Translations };

const condition =
    /^\s*(?:\{(\*|-?\d+)\}|\[(\*|-?\d+)\s*,\s*(\*|-?\d+)\])\s*([\s\S]*)$/;

/**
 * Pick the form of a Laravel plural string that applies to the count.
 *
 * Forms are separated by "|", either as "singular|plural" or with explicit
 * conditions such as "{0} None|{1} One|[2,*] :count", exactly as Laravel's
 * own trans_choice() reads them.
 */
function choose(line: string, count: number): string {
    const forms = line.split('|');

    for (const form of forms) {
        const match = condition.exec(form);

        if (!match) continue;

        const [, exact, from, to, text = ''] = match;
        const applies =
            exact !== undefined
                ? exact === '*' || Number(exact) === count
                : (from === '*' || count >= Number(from)) &&
                  (to === '*' || count <= Number(to));

        if (applies) return text;
    }

    const plain = forms.filter((form) => !condition.test(form));

    return (
        (count === 1 ? plain[0] : plain[1]) ?? plain[plain.length - 1] ?? line
    );
}

// Laravel's plural syntax, applied whenever a count is passed to t().
const laravelPlurals: PostProcessorModule = {
    type: 'postProcessor',
    name: 'laravelPlurals',
    process(value, _key, options) {
        const count = options.count;

        return typeof count === 'number' && value.includes('|')
            ? choose(value, count)
            : value;
    },
};

/**
 * Point i18next at the translations Laravel shared for the current locale.
 */
export function configureI18n(
    locale: string,
    translations: Translations,
): void {
    if (i18n.isInitialized) {
        if (
            i18n.language === locale &&
            i18n.hasResourceBundle(locale, 'translation')
        ) {
            return;
        }

        i18n.addResourceBundle(locale, 'translation', translations, true, true);
        void i18n.changeLanguage(locale);

        return;
    }

    void i18n
        .use(initReactI18next)
        .use(laravelPlurals)
        .init({
            lng: locale,
            fallbackLng: false,
            resources: { [locale]: { translation: translations } },
            // Laravel's ":placeholder" syntax, so a language file reads the same on both sides.
            // The default "{{" and "}}" take precedence over the escaped forms, so they're cleared.
            interpolation: {
                escapeValue: false,
                prefix: '',
                suffix: '',
                prefixEscaped: ':',
                suffixEscaped: '(?=\\W|$)',
            },
            postProcess: ['laravelPlurals'],
            react: { bindI18nStore: 'added' },
        });
}
