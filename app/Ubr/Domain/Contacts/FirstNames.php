<?php

namespace App\Ubr\Domain\Contacts;

/**
 * Słownik imion (bez diakrytyków) — polskie najczęstsze + popularne angielskie / niemieckie.
 * Służy wyłącznie do odróżnienia osoby od innego tekstu na stronie (nie do zgadywania danych).
 * Filtr `ubr/contacts/first_names` pozwala dopisać imiona bez zmiany kodu.
 */
final class FirstNames
{
	private const NAMES = 'adam adrian agata agnieszka aleksander aleksandra alicja amelia ana anastazja andrzej aneta angelika anita anna antoni antonina arkadiusz artur bartlomiej bartosz beata benedykt bernadeta blazej bogdan boguslaw boguslawa bogumil bogumila bogna bozena bronislaw cezary celina czeslaw damian daniel daniela danuta dariusz dawid dominik dominika dorota edward edyta elzbieta emil emilia eryk ewa ewelina filip franciszek gabriel gabriela genowefa grazyna grzegorz halina hanna henryk helena hubert ignacy igor ilona irena ireneusz iwona izabela jacek jadwiga jakub jan janina janusz jaroslaw jaroslawa jerzy joanna jolanta jozef jozefa julia julian julita justyna kacper kamil kamila karina karol karolina katarzyna kazimierz kinga klaudia konrad krystian krystyna krzysztof lech leszek lidia liliana lucjan lucyna ludwik lukasz maciej magdalena maja malgorzata marcel marcin marek maria marian marianna mariola mariusz marlena marta martyna mateusz maurycy michal michalina miroslaw miroslawa monika natalia nikola nikodem norbert oliwer oliwia oskar patrycja patryk pawel piotr przemyslaw radoslaw rafal regina remigiusz renata robert roman romuald ryszard sabina sebastian slawomir stanislaw stanislawa stefan sylwia sylwester szymon tadeusz teresa tomasz urszula wanda waldemar wieslaw wieslawa wiktor wiktoria wincenty witold wladyslaw wlodzimierz wojciech zbigniew zdzislaw zenon zofia zuzanna zygmunt '
		. 'aaron alan albert alex alexander alexandra alice amanda amy andrea andreas andrew andy angela ann anne anthony barbara ben benjamin bernd bettina bob brian carl carlos caroline catherine charles charlotte chris christian christina christine christoph christopher claudia daniel david dennis dieter dirk donald emma eric erik eva frank frederic george gerhard guenter hans harald heike heinz helmut ian jack james jane jason jean jennifer jessica jim joachim joe johannes john jonathan jorg joseph juergen julie juliane jurgen karen karl kate katharina kevin klaus laura linda lisa lukas manfred marc marco maria mark markus martin mary matthew matthias max melanie michael michaela michele mike nicole nina oliver olivia patrick paul peter philip philipp rachel rainer ralf richard robert sabine sam samuel sandra sarah sascha scott sebastian simon sophie stefan stephan stephanie steve steven susan susanne thomas tim tobias tom ulrich ursula uwe valentina victoria walter werner william wolfgang';

	private const ALSO_SURNAME = ['marek', 'adam', 'jan', 'stanislaw', 'jakub', 'kazimierz', 'bogdan', 'lucjan', 'roman', 'dawid', 'wojciech', 'paul', 'james', 'thomas', 'john', 'martin', 'michael', 'george', 'walter', 'werner', 'frank', 'simon', 'max', 'sebastian', 'emil', 'gabriel', 'oskar', 'karol', 'filip', 'cezary', 'wiktor', 'lech'];

	/** @var array<string, true>|null */
	private static ?array $set = null;

	public static function has(string $name): bool
	{
		if (self::$set === null) {
			$names = preg_split('/\s+/', trim(self::NAMES)) ?: [];
			$names = (array) apply_filters('ubr/contacts/first_names', $names);
			self::$set = array_fill_keys(array_map(fn ($n) => PersonName::fold((string) $n), $names), true);
		}

		return isset(self::$set[PersonName::fold($name)]);
	}

	public static function alsoSurname(string $name): bool
	{
		return in_array(PersonName::fold($name), self::ALSO_SURNAME, true);
	}
}
