<?php
namespace Entity\Repository;

use Entity;

class SongHistoryRepository extends \App\Doctrine\Repository
{
    public function getNextSongForStation(Entity\Station $station, $is_autodj = false)
    {
        $next_song = $this->_em->createQuery('SELECT sh, s, sm
            FROM ' . $this->_entityName . ' sh JOIN sh.song s JOIN sh.media sm
            WHERE sh.station_id = :station_id
            AND sh.timestamp_cued >= :threshold
            AND sh.sent_to_autodj = 0
            AND sh.timestamp_start = 0
            AND sh.timestamp_end = 0
            ORDER BY sh.id DESC')
            ->setParameter('station_id', $station->id)
            ->setParameter('threshold', time() - 60 * 15)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        if (!($next_song instanceof Entity\SongHistory)) {
            /** @var Entity\Repository\StationMediaRepository $media_repo */
            $media_repo = $this->_em->getRepository(Entity\StationMedia::class);

            $next_song = $media_repo->getNextSong($station);
        }

        if ($is_autodj) {
            $next_song->sent_to_autodj = true;

            $this->_em->persist($next_song);
            $this->_em->flush();
        }

        return $next_song;
    }

    /**
     * @param int $num_entries
     * @return array
     */
    public function getHistoryForStation(Entity\Station $station, $num_entries = 5)
    {
        $history = $this->_em->createQuery('SELECT sh, s 
            FROM ' . $this->_entityName . ' sh JOIN sh.song s LEFT JOIN sh.media sm  
            WHERE sh.station_id = :station_id 
            AND sh.timestamp_end != 0
            ORDER BY sh.id DESC')
            ->setParameter('station_id', $station->id)
            ->setMaxResults($num_entries)
            ->execute();

        $return = [];
        foreach ($history as $sh) {
            $return[] = $sh->api();
        }

        return $return;
    }

    /**
     * @param Entity\Song $song
     * @param Entity\Station $station
     * @param $np
     * @return Entity\SongHistory
     */
    public function register(Entity\Song $song, Entity\Station $station, $np)
    {
        // Pull the most recent history item for this station.
        $last_sh = $this->_em->createQuery('SELECT sh FROM Entity\SongHistory sh
            WHERE sh.station_id = :station_id
            ORDER BY sh.timestamp_start DESC')
            ->setParameter('station_id', $station->id)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        $listeners = (int)$np['listeners']['current'];

        if ($last_sh instanceof Entity\SongHistory && $last_sh->song_id == $song->id) {
            // Updating the existing SongHistory item with a new data point.
            $delta_points = (array)$last_sh->delta_points;
            $delta_points[] = $listeners;

            $last_sh->delta_points = $delta_points;

            $this->_em->persist($last_sh);
            $this->_em->flush();

            return $last_sh;
        } else {
            // Wrapping up processing on the previous SongHistory item (if present).
            if ($last_sh instanceof Entity\SongHistory) {
                $last_sh->timestamp_end = time();
                $last_sh->listeners_end = $listeners;

                // Calculate "delta" data for previous item, based on all data points.
                $delta_points = (array)$last_sh->delta_points;
                $delta_points[] = $listeners;
                $last_sh->delta_points = $delta_points;

                $delta_positive = 0;
                $delta_negative = 0;
                $delta_total = 0;

                for ($i = 1; $i < count($delta_points); $i++) {
                    $current_delta = $delta_points[$i];
                    $previous_delta = $delta_points[$i - 1];

                    $delta_delta = $current_delta - $previous_delta;
                    $delta_total += $delta_delta;

                    if ($delta_delta > 0) {
                        $delta_positive += $delta_delta;
                    } elseif ($delta_delta < 0) {
                        $delta_negative += abs($delta_delta);
                    }
                }

                $last_sh->delta_positive = $delta_positive;
                $last_sh->delta_negative = $delta_negative;
                $last_sh->delta_total = $delta_total;

                /** @var ListenerRepository $listener_repo */
                $listener_repo = $this->_em->getRepository(Entity\Listener::class);
                $last_sh->unique_listeners = $listener_repo->getUniqueListeners($station, $last_sh->timestamp_start, time());

                $this->_em->persist($last_sh);
            }

            // Look for an already cued but unplayed song.
            $sh = $this->_em->createQuery('SELECT sh FROM Entity\SongHistory sh
                WHERE sh.station_id = :station_id
                AND sh.song_id = :song_id
                AND sh.timestamp_cued != 0
                AND sh.timestamp_start = 0
                ORDER BY sh.timestamp_cued DESC')
                ->setParameter('station_id', $station->id)
                ->setParameter('song_id', $song->id)
                ->setMaxResults(1)
                ->getOneOrNullResult();

            // Processing a new SongHistory item.
            if (!($sh instanceof Entity\SongHistory))
            {
                $sh = new Entity\SongHistory;
                $sh->song = $song;
                $sh->station = $station;
            }

            $sh->timestamp_start = time();
            $sh->listeners_start = $listeners;
            $sh->delta_points = [$listeners];

            $this->_em->persist($sh);
            $this->_em->flush();

            return $sh;
        }
    }
}