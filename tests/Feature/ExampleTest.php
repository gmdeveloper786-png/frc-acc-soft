<?php

test('the application directs guests to login', function () {
    $this->get('/')->assertRedirect(route('login'));
});
