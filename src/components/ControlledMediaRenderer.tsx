import React, { useState } from "react";
import ACastAudio from "./ACastAudio";
import YouTubeVideo from "./YouTubeVideo";
import MediaRendererControl from "./MediaRendererControl";

interface Media {
  mediaType: string;
  contentID: string;
  description: string; // Optional description for each media element
  publishedDate?: string; //Optional published date for each media element
}

interface ControlledMediaRendererProps {
  mediaArray: Media[];
  selectedMediaIndex: number;
}

const ControlledMediaRenderer: React.FC<ControlledMediaRendererProps> = ({
  mediaArray,
  selectedMediaIndex,
}) => {
  const [currentMediaIndex, setCurrentMediaIndex] = useState(0);
  const currentMedia = mediaArray[currentMediaIndex];

  const handlePrevMedia = () => {
    setCurrentMediaIndex((prevIndex) => Math.max(prevIndex - 1, 0));
  };

  const handleNextMedia = () => {
    setCurrentMediaIndex((prevIndex) =>
      Math.min(prevIndex + 1, mediaArray.length - 1)
    );
  };

  return (
    <div>
      {/* Control bar */}
      <MediaRendererControl
        currentMediaIndex={currentMediaIndex}
        totalMediaCount={mediaArray.length}
        onPrevMedia={handlePrevMedia}
        onNextMedia={handleNextMedia}
        className="media-renderer-control"
      />
      <div className="current-media-information">
        {/* Display optional description */}
        {currentMedia.description && (
          <div className="description">
            <h2>{currentMedia.description}</h2>
          </div>
        )}
        {/* Display optional published date */}
        {currentMedia.publishedDate && (
          <div className="publishedDate">
            <h3>{currentMedia.publishedDate}</h3>
          </div>
        )}
      </div>
      {currentMedia.mediaType === "ACastAudio" ? (
        <ACastAudio
          contentID={currentMedia.contentID}
          description={currentMedia.description}
        />
      ) : currentMedia.mediaType === "YouTubeVideo" ? (
        <YouTubeVideo
          contentID={currentMedia.contentID}
          description={currentMedia.description}
        />
      ) : null}
    </div>
  );
};

export default ControlledMediaRenderer;
