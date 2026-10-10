<?php

/**
 * Ugly but Rich — ekrany panelu (pod /app/...). Klucz = segment URL (pusty = dashboard).
 * Każdy ekran wymaga zalogowania i członkostwa w organizacji (AppPages).
 */

return [
	'dashboard' => ['slug' => '', 'title' => 'Dashboard', 'icon' => 'home', 'view' => 'ubr.pages.dashboard'],
	'discover' => ['slug' => 'discover', 'title' => 'Discover', 'icon' => 'search', 'view' => 'ubr.pages.discover'],
	'prospects' => ['slug' => 'prospects', 'title' => 'Prospects', 'icon' => 'bookmark', 'view' => 'ubr.pages.prospects'],
	'pipeline' => ['slug' => 'pipeline', 'title' => 'Pipeline', 'icon' => 'board', 'view' => 'ubr.pages.pipeline'],
	'settings' => ['slug' => 'settings', 'title' => 'Settings', 'icon' => 'settings', 'view' => 'ubr.pages.settings'],
	// Callback OAuth ClickUp (Redirect URI) — bez pozycji w menu, bez widoku (przekierowanie do Settings).
	'clickup-callback' => ['slug' => 'clickup-callback', 'title' => 'ClickUp', 'icon' => 'settings', 'view' => '', 'hidden' => true],
];
