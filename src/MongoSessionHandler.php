<?php
namespace Altmetric;

use MongoDB\Collection;
use MongoDB\BSON\Binary;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\Exception as MongoDBException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class MongoSessionHandler implements \SessionHandlerInterface
{
    private $collection;
    private $logger;

    public function __construct(Collection $collection, LoggerInterface $logger = null): void
    {
        $this->collection = $collection;

        if ($logger === null) {
            $this->logger = new NullLogger();
        } else {
            $this->logger = $logger;
        }
    }

    public function open($_save_path, $_name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string
    {
        $this->logger->debug("Reading session {$id}");

        $session = $this->collection->findOne(['_id' => $id], ['projection' => ['data' => 1]]);

        if ($session) {
            $this->logger->debug("Session {$id} found, returning data");

            return $session['data']->getData();
        } else {
            $this->logger->debug("No session {$id} found, returning no data");

            return '';
        }
    }

    public function write($id, $data): bool
    {
        $session = [
            '_id' => $id,
            'data' => new Binary($data, Binary::TYPE_OLD_BINARY),
            'last_accessed' => new UTCDateTime(floor(microtime(true) * 1000))
        ];

        try {
            $this->logger->debug("Saving data {$data} to session {$id}");
            $this->collection->replaceOne(['_id' => $id], $session, ['upsert' => true]);

            return true;
        } catch (MongoDBException $e) {
            $this->logger->error("Error when saving {$data} to session {$id}: {$e->getMessage()}");

            return false;
        }
    }

    public function destroy($id): bool
    {
        $this->logger->debug("Destroying session {$id}");

        try {
            $this->collection->deleteOne(['_id' => $id]);

            return true;
        } catch (MongoDBException $e) {
            $this->logger->error("Error removing session {$id}: {$e->getMessage()}");

            return false;
        }
    }

    public function gc($maxlifetime): int
    {
        $lastAccessed = new UTCDateTime(floor((microtime(true) - $maxlifetime) * 1000));

        try {
            $this->logger->debug("Removing any sessions older than {$lastAccessed}");
            $this->collection->deleteMany(['last_accessed' => ['$lt' => $lastAccessed]]);

            return true;
        } catch (MongoDBException $e) {
            $this->logger->error("Error removing sessions older than {$lastAccessed}: {$e->getMessage()}");

            return false;
        }
    }
}
