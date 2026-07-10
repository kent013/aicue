@extends('errors.admin.layout')
@section('code', $status)
@section('title', 'エラーが発生しました')
@section('message', 'リクエストを処理できませんでした。アクセス権限や URL をご確認ください。')
