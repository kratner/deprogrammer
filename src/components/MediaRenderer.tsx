import React from 'react';
import ACastAudio from './ACastAudio';
import YouTubeVideo from './YouTubeVideo';

interface Media {
  mediaType: string;
  contentID: string;
}

interface MediaRendererProps {
  mediaArray: Media[];
}

const MediaRenderer: React.FC<MediaRendererProps> = ({ mediaArray }) => {
  return (
    <div>
      {mediaArray.map((media, index) => {
        if (media.mediaType === 'ACastAudio') {
          return <ACastAudio key={index} contentID={media.contentID} />;
        } else if (media.mediaType === 'YouTubeVideo') {
          return <YouTubeVideo key={index} contentID={media.contentID} />;
        }
        // Add more conditionals for other media types as needed

        return null; // Render nothing if the media type is not supported
      })}
    </div>
  );
};

export default MediaRenderer;
