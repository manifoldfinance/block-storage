#!/usr/bin/env bash
sudo ./run.sh `ls /dev/nvme[0-9]n1 | sed -e 's/\//\--target=\//'` \
--nopurge –noprecondition --fio_direct=1 --fio_size=10g --test=iops \
--skip_blocksize=512b --skip_blocksize=8k --skip_blocksize=16k \ 
--skip_blocksize=32k --skip_blocksize=64k --skip_blocksize=128k \
--skip_blocksize=1m;

sleep 1
