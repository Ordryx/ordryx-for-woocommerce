<?php
/**
 * Action Scheduler's functions the plugin calls (WooCommerce bundles it), for
 * PHPStan only.
 *
 * @package Ordryx
 */

/**
 * @param string $hook  Hook.
 * @param array<mixed>  $args  Arguments.
 * @param string $group Group.
 */
function as_has_scheduled_action( $hook, $args = null, $group = '' ): bool {}

/**
 * @param int    $timestamp When.
 * @param string $hook      Hook.
 * @param array<mixed>  $args      Arguments.
 * @param string $group     Group.
 * @param bool   $unique    Unique.
 */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false ): int {}

/**
 * @param int    $timestamp When.
 * @param int    $interval  Seconds between.
 * @param string $hook      Hook.
 * @param array<mixed>  $args      Arguments.
 * @param string $group     Group.
 */
function as_schedule_recurring_action( $timestamp, $interval, $hook, $args = array(), $group = '', $unique = false ): int {}

/**
 * @param string $hook  Hook.
 * @param array<mixed>  $args  Arguments.
 * @param string $group Group.
 * @return int|bool A pending action's time, true if one is only running, else false.
 */
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {}

/**
 * @param string $hook  Hook.
 * @param array<mixed>  $args  Arguments.
 * @param string $group Group.
 */
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ): void {}

/**
 * @param array<mixed> $args          Query.
 * @param string       $return_format Format.
 * @return array<mixed>
 */
function as_get_scheduled_actions( $args = array(), $return_format = 'OBJECT' ) {}
