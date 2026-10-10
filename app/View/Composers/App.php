<?php

namespace App\View\Composers;

use Roots\Acorn\View\Composer;

class App extends Composer
{
	/**
	 * List of views served by this composer.
	 *
	 * @var array
	 */
	protected static $views = [
		'*', // działa globalnie
	];

	/**
	 * Dane dostępne we wszystkich widokach Blade.
	 */
	public function with(): array
	{
		// Panel Ugly but Rich (/app) nie korzysta z danych motywu strony (ACF).
		if (str_starts_with($this->view->name(), 'ubr.')) {
			return [];
		}

		return [
			'siteName' => $this->siteName(),
			'logo' => get_field('logo', 'option'),
			'logo_footer' => get_field('logo_footer', 'option'),
			'footer_contact' => get_field('footer_contact', 'option'),
		];
	}

	/**
	 * Zwraca nazwę strony.
	 */
	public function siteName(): string
	{
		return get_bloginfo('name', 'display');
	}
}
