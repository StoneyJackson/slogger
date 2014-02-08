<?php

unlink('log.lock');
unlink(glob('log_*.csv')[0]);
array_map('unlink', glob('../logTest/*'));
rmdir('../logTest');
