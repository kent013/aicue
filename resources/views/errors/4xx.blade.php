@extends('errors.layout')
@section('code', $exception->getStatusCode())
@section('title', 'エラーが発生しました')
@section('message', 'リクエストを処理できませんでした。')
