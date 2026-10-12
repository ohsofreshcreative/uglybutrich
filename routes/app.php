<?php

/**
 * Ugly but Rich — ekrany panelu (pod /app/...). Klucz = segment URL (pusty = dashboard).
 * Każdy ekran wymaga zalogowania i członkostwa w organizacji (AppPages) — poza ekranem logowania (AppLogin).
 */

return [
	'dashboard' => ['slug' => '', 'title' => 'Dashboard', 'icon' => 'home', 'view' => 'ubr.pages.dashboard'],
	'discover' => ['slug' => 'discover', 'title' => 'Discover', 'icon' => 'search', 'view' => 'ubr.pages.discover'],
	'prospects' => ['slug' => 'prospects', 'title' => 'Prospects', 'icon' => 'bookmark', 'view' => 'ubr.pages.prospects'],
	'pipeline' => ['slug' => 'pipeline', 'title' => 'Pipeline', 'icon' => 'board', 'view' => 'ubr.pages.pipeline'],
	'sequences' => ['slug' => 'sequences', 'title' => 'Sequences', 'icon' => 'mail', 'view' => 'ubr.pages.sequences'],
	'settings' => ['slug' => 'settings', 'title' => 'Settings', 'icon' => 'settings', 'view' => 'ubr.pages.settings'],
	// Callback OAuth ClickUp (Redirect URI) — bez pozycji w menu, bez widoku (przekierowanie do Settings).
	'clickup-callback' => ['slug' => 'clickup-callback', 'title' => 'ClickUp', 'icon' => 'settings', 'view' => '', 'hidden' => true],
	// Logowanie do panelu (publiczne — jedyny ekran /app bez sesji), bez pozycji w menu.
	'login' => ['slug' => 'login', 'title' => 'Logowanie', 'icon' => 'settings', 'view' => 'ubr.pages.login', 'hidden' => true],
];
