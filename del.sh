#!/bin/sh

docker rm -v $(docker ps -aq -f status=exited)
docker rmi -f $(docker images -q)