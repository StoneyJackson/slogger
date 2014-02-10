<?php

unlink('.lockfile');
unlink(glob('log_*.csv')[0]);
array_map('unlink', glob('../logTest/*'));
unlink('../logTest/.lockfile');
rmdir('../logTest');
