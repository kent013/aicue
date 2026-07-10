@extends('errors.layout')
@section('code', $exception->getStatusCode())
@section('title', 'サーバーエラー')
@section('message', 'サーバーで問題が発生しました。しばらくお待ちください。')
