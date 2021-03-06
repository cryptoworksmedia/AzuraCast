<?php
namespace Entity;

use Doctrine\Common\Collections\ArrayCollection;

/**
 * @Table(name="station_media", indexes={
 *   @index(name="search_idx", columns={"title", "artist", "album"})
 * }, uniqueConstraints={
 *   @UniqueConstraint(name="path_unique_idx", columns={"path", "station_id"})
 * })
 * @Entity(repositoryClass="Entity\Repository\StationMediaRepository")
 * @HasLifecycleCallbacks
 */
class StationMedia extends \App\Doctrine\Entity
{
    public function __construct()
    {
        $this->length = 0;
        $this->length_text = '0:00';

        $this->mtime = 0;

        $this->playlists = new ArrayCollection();
    }

    /**
     * @Column(name="id", type="integer")
     * @Id
     * @GeneratedValue(strategy="IDENTITY")
     */
    protected $id;

    /** @Column(name="station_id", type="integer") */
    protected $station_id;

    /** @Column(name="song_id", type="string", length=50, nullable=true) */
    protected $song_id;

    /** @Column(name="title", type="string", length=200, nullable=true) */
    protected $title;

    /** @Column(name="artist", type="string", length=200, nullable=true) */
    protected $artist;

    /** @Column(name="album", type="string", length=200, nullable=true) */
    protected $album;

    /**
     * The track ISRC (International Standard Recording Code), used for licensing purposes.
     * @Column(name="isrc", type="string", length=15, nullable=true)
     */
    protected $isrc;

    /** @Column(name="length", type="smallint") */
    protected $length;

    public function setLength($length)
    {
        $length_min = floor($length / 60);
        $length_sec = $length % 60;

        $this->length = round($length);
        $this->length_text = $length_min . ':' . str_pad($length_sec, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Get the length with cue-in and cue-out points included.
     *
     * @return int
     */
    public function getCalculatedLength()
    {
        $length = (int)$this->length;

        if ((int)$this->cue_out > 0) {
            $length_removed = $length - (int)$this->cue_out;
            $length -= $length_removed;
        }
        if ((int)$this->cue_in > 0) {
            $length -= $this->cue_in;
        }

        return $length;
    }

    /** @Column(name="length_text", type="string", length=10, nullable=true) */
    protected $length_text;

    /** @Column(name="path", type="string", length=191, nullable=true) */
    protected $path;

    public function getFullPath()
    {
        $media_base_dir = $this->station->getRadioMediaDir();

        return $media_base_dir . '/' . $this->path;
    }

    /** @Column(name="mtime", type="integer", nullable=true) */
    protected $mtime;

    /** @Column(name="fade_overlap", type="decimal", precision=3, scale=1, nullable=true) */
    protected $fade_overlap;

    /** @Column(name="fade_in", type="decimal", precision=3, scale=1, nullable=true) */
    protected $fade_in;

    /** @Column(name="fade_out", type="decimal", precision=3, scale=1, nullable=true) */
    protected $fade_out;

    /** @Column(name="cue_in", type="decimal", precision=5, scale=1, nullable=true) */
    protected $cue_in;

    /** @Column(name="cue_out", type="decimal", precision=5, scale=1, nullable=true) */
    protected $cue_out;

    /**
     * Assemble a list of annotations for LiquidSoap.
     *
     * @return array
     */
    public function getAnnotations()
    {
        $annotations = [];
        $annotation_types = [
            'song_id' => 'song_id',
            'title' => 'title',
            'artist' => 'artist',
            'fade_overlap' => 'liq_start_next',
            'fade_in' => 'liq_fade_in',
            'fade_out' => 'liq_fade_out',
            'cue_in' => 'liq_cue_in',
            'cue_out' => 'liq_cue_out',
        ];

        foreach($annotation_types as $annotation_property => $annotation_name) {
            if ($this->$annotation_property !== null) {
                $prop = $this->$annotation_property;
                $prop = preg_replace('/[^\00-\255]+/u', '', $prop);
                $prop = str_replace(['"', "\n", "\t", "\r"], ["'", '', '', ''], $prop);
                $annotations[] = $annotation_name . '="' . $prop . '"';
            }
        }

        return $annotations;
    }

    /**
     * @ManyToOne(targetEntity="Station", inversedBy="media")
     * @JoinColumns({
     *   @JoinColumn(name="station_id", referencedColumnName="id", onDelete="CASCADE")
     * })
     * @var Station
     */
    protected $station;

    /**
     * @ManyToOne(targetEntity="Song")
     * @JoinColumns({
     *   @JoinColumn(name="song_id", referencedColumnName="id", onDelete="SET NULL")
     * })
     * @var Song|null
     */
    protected $song;

    /**
     * @ManyToMany(targetEntity="StationPlaylist", inversedBy="playlists")
     * @JoinTable(name="station_playlist_has_media",
     *   joinColumns={@JoinColumn(name="media_id", referencedColumnName="id", onDelete="CASCADE")},
     *   inverseJoinColumns={@JoinColumn(name="playlists_id", referencedColumnName="id", onDelete="CASCADE")}
     * )
     */
    protected $playlists;

    /**
     * Process metadata information from media file.
     *
     * @param bool $force
     * @return array|bool
     *   - Array containing song information, if one is detected and needs updating
     *   - False if information was not updated
     */
    public function loadFromFile($force = false)
    {
        if (empty($this->path)) {
            return false;
        }

        $media_base_dir = $this->station->getRadioMediaDir();
        $media_path = $media_base_dir . '/' . $this->path;

        $path_parts = pathinfo($media_path);

        // Only update metadata if the file has been updated.
        $media_mtime = filemtime($media_path);

        if ($media_mtime > $this->mtime || !$this->song_id || $force) {

            $this->mtime = $media_mtime;

            // Load metadata from supported files.
            $id3 = new \getID3();

            $id3->option_md5_data = true;
            $id3->option_md5_data_source = true;
            $id3->encoding = 'UTF-8';

            $file_info = $id3->analyze($media_path);

            if (empty($file_info['error'])) {
                $this->setLength($file_info['playtime_seconds']);

                $tags_to_set = ['title', 'artist', 'album'];
                if (!empty($file_info['tags'])) {
                    foreach ($file_info['tags'] as $tag_type => $tag_data) {
                        foreach ($tags_to_set as $tag) {
                            if (!empty($tag_data[$tag][0])) {
                                $this->{$tag} = $tag_data[$tag][0];
                            }
                        }
                    }
                }
            }

            // Attempt to derive title and artist from filename.
            if (empty($this->title)) {
                $filename = str_replace('_', ' ', $path_parts['filename']);

                $string_parts = explode('-', $filename);

                // If not normally delimited, return "text" only.
                if (count($string_parts) == 1) {
                    $this->title = trim($filename);
                    $this->artist = '';
                } else {
                    $this->title = trim(array_pop($string_parts));
                    $this->artist = trim(implode('-', $string_parts));
                }
            }

            return [
                'artist' => $this->artist,
                'title' => $this->title,
            ];
        }

        return false;
    }

    /**
     * Write modified metadata directly to the file as ID3 information.
     */
    public function writeToFile()
    {
        $getID3 = new \getID3;
        $getID3->setOption(['encoding' => 'UTF8']);

        require_once(APP_INCLUDE_VENDOR . '/james-heinrich/getid3/getid3/write.php');

        $tagwriter = new \getid3_writetags;
        $tagwriter->filename = $this->getFullPath();

        $tagwriter->tagformats = ['id3v1', 'id3v2.3'];
        $tagwriter->overwrite_tags = true;
        $tagwriter->tag_encoding = 'UTF8';
        $tagwriter->remove_other_tags = true;

        $tag_data = [
            'title' => [$this->title],
            'artist' => [$this->artist],
            'album' => [$this->album],
        ];

        $tagwriter->tag_data = $tag_data;

        // write tags
        if ($tagwriter->WriteTags()) {
            $this->mtime = time();
            return true;
        }

        return false;
    }

    /**
     * Retrieve the API version of the object/array.
     *
     * @return Api\Song
     */
    public function api()
    {
        $response = new Api\Song;
        $response->id = (string)$this->song_id;
        $response->text = $this->artist.' - '.$this->title;
        $response->artist = (string)$this->artist;
        $response->title = (string)$this->title;

        return $response;
    }
}